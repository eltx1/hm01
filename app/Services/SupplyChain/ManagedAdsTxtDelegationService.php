<?php

namespace App\Services\SupplyChain;

use App\Enums\AdsTxtDeploymentMode;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

final class ManagedAdsTxtDelegationService
{
    public function __construct(private readonly SupplyChainArtifactBuilder $artifacts) {}

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
            $first = Http::withOptions(['allow_redirects' => false])->timeout(10)->get($source);
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
            $final = Http::withOptions(['allow_redirects' => false])->timeout(10)->get($target);
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
        if (trim($final->body()) !== trim($this->artifacts->adsTxtForSite($site))) {
            return $this->record($settings, false, 'ADS_TXT_MANAGED_PAYLOAD_MISMATCH', $source, $target, $final->status(), $final->header('Content-Type'));
        }

        return $this->record($settings, true, 'VERIFIED', $source, $target, $final->status(), $final->header('Content-Type'));
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
