<?php

namespace App\Services\SupplyChain;

use App\Enums\AdsTxtDeploymentMode;
use App\Models\Site;
use App\Services\Campaigns\RemoteUrlSafetyValidator;
use Illuminate\Support\Facades\Http;

final class ManagedAdsTxtDelegationService
{
    private const MAX_RESPONSE_BYTES = 1_048_576;

    public function __construct(
        private readonly SupplyChainArtifactBuilder $artifacts,
        private readonly RemoteUrlSafetyValidator $urls,
    ) {}

    public function managedUrlForSite(Site $site): string
    {
        $domain = preg_replace('/[^a-z0-9.-]/', '', strtolower($site->primary_domain)) ?: 'invalid';

        return rtrim((string) config('supply-chain.managed_ads_txt_base_url'), '/')
            .'/supply/domains/'.$domain.'/ads.txt';
    }

    /** @return array{valid: bool, code: string, source_url: string, target_url: string, http_status: ?int, content_type: ?string} */
    public function verify(Site $site): array
    {
        $settings = $site->servingSettings()->firstOrFail();
        $source = 'https://'.strtolower($site->primary_domain).'/ads.txt';
        $target = $this->managedUrlForSite($site);

        if ($settings->ads_txt_deployment_mode !== AdsTxtDeploymentMode::ManagedRedirectDelegation) {
            return $this->record($settings, false, 'ADS_TXT_MANAGED_DELEGATION_NOT_ENABLED', $source, $target);
        }

        try {
            [$first] = $this->safeGet($source, false);
        } catch (\Throwable) {
            return $this->record($settings, false, 'ADS_TXT_REDIRECT_SOURCE_UNREACHABLE', $source, $target);
        }

        if ($first->status() < 300 || $first->status() >= 400) {
            return $this->record($settings, false, 'ADS_TXT_REDIRECT_REQUIRED', $source, $target, $first->status(), $first->header('Content-Type'));
        }
        $location = trim((string) $first->header('Location'));
        if ($location === '' || ! hash_equals($target, $location)) {
            return $this->record($settings, false, 'ADS_TXT_REDIRECT_TARGET_MISMATCH', $source, $target, $first->status(), $first->header('Content-Type'));
        }

        try {
            [$final, $body, $tooLarge] = $this->safeGet($target, true);
        } catch (\Throwable) {
            return $this->record($settings, false, 'ADS_TXT_MANAGED_TARGET_UNREACHABLE', $source, $target);
        }

        if ($final->status() >= 300 && $final->status() < 400) {
            return $this->record($settings, false, 'ADS_TXT_REDIRECT_CHAIN_INVALID', $source, $target, $final->status(), $final->header('Content-Type'));
        }
        $contentType = strtolower((string) $final->header('Content-Type'));
        if (! $final->successful() || ! str_starts_with($contentType, 'text/plain')) {
            return $this->record($settings, false, 'ADS_TXT_MANAGED_TARGET_INVALID', $source, $target, $final->status(), $final->header('Content-Type'));
        }
        if ($tooLarge) {
            return $this->record($settings, false, 'ADS_TXT_MANAGED_TARGET_TOO_LARGE', $source, $target, $final->status(), $final->header('Content-Type'));
        }
        if (trim($body) !== trim($this->artifacts->adsTxtForSite($site))) {
            return $this->record($settings, false, 'ADS_TXT_MANAGED_PAYLOAD_MISMATCH', $source, $target, $final->status(), $final->header('Content-Type'));
        }

        return $this->record($settings, true, 'VERIFIED', $source, $target, $final->status(), $final->header('Content-Type'));
    }

    /** @return array{0: \Illuminate\Http\Client\Response, 1: string, 2: bool} */
    private function safeGet(string $url, bool $readBody): array
    {
        $addresses = $this->urls->publicAddresses($url, 'ads_txt_delegation_url');
        $address = collect($addresses)->first(fn (string $item): bool => filter_var($item, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) ?? $addresses[0];
        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
        if (! defined('CURLOPT_RESOLVE')) {
            throw new \RuntimeException('Safe DNS-pinned HTTP transport is unavailable.');
        }
        $pinnedAddress = str_contains($address, ':') ? '['.$address.']' : $address;
        $options = [
            'allow_redirects' => false,
            'stream' => true,
            'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$pinnedAddress]],
        ];

        $response = Http::connectTimeout(3)->timeout(10)->withOptions($options)->get($url);
        if (! $readBody) {
            return [$response, '', false];
        }

        $declaredBytes = (int) ($response->header('Content-Length') ?: 0);
        if ($declaredBytes > self::MAX_RESPONSE_BYTES) {
            return [$response, '', true];
        }
        $stream = $response->toPsrResponse()->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $body = '';
        while (! $stream->eof() && strlen($body) <= self::MAX_RESPONSE_BYTES) {
            $chunk = $stream->read(min(8192, (self::MAX_RESPONSE_BYTES + 1) - strlen($body)));
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }

        return [$response, $body, strlen($body) > self::MAX_RESPONSE_BYTES || ! $stream->eof()];
    }

    /** @return array{valid: bool, code: string, source_url: string, target_url: string, http_status: ?int, content_type: ?string} */
    private function record($settings, bool $valid, string $code, string $source, string $target, ?int $status = null, ?string $contentType = null): array
    {
        $settings->update([
            'ads_txt_redirect_target' => $target,
            'ads_txt_redirect_status' => $code,
            'ads_txt_redirect_verified_at' => $valid ? now() : null,
        ]);

        return [
            'valid' => $valid,
            'code' => $code,
            'source_url' => $source,
            'target_url' => $target,
            'http_status' => $status,
            'content_type' => $contentType,
        ];
    }
}
