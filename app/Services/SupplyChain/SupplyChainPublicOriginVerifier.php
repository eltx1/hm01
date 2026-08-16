<?php

namespace App\Services\SupplyChain;

use App\Models\SupplyChainOriginCheck;
use Illuminate\Support\Facades\Http;

final class SupplyChainPublicOriginVerifier
{
    public const ARTIFACT_SELLERS_JSON = 'SELLERS_JSON';

    public function __construct(private readonly SupplyChainArtifactBuilder $artifacts) {}

    /** @return array<string, mixed> */
    public function verifySellersJson(): array
    {
        $canonical = (string) config('supply-chain.canonical_sellers_json_url');
        $proxyTarget = (string) config('supply-chain.sellers_json_proxy_target');
        $expected = $this->artifacts->sellersJson();
        $expectedHash = hash('sha256', $expected);
        $finalUrl = $canonical;

        try {
            $response = Http::withOptions(['allow_redirects' => false])->timeout(10)->get($canonical);
            if ($response->status() >= 300 && $response->status() < 400) {
                $location = trim((string) $response->header('Location'));
                if ($location === '' || $proxyTarget === '' || ! hash_equals($proxyTarget, $location)) {
                    return $this->persist(false, 'SELLERS_JSON_CANONICAL_REDIRECT_INVALID', $canonical, $location ?: null, $response->status(), $response->header('Content-Type'), null);
                }
                $finalUrl = $location;
                $response = Http::withOptions(['allow_redirects' => false])->timeout(10)->get($location);
                if ($response->status() >= 300 && $response->status() < 400) {
                    return $this->persist(false, 'SELLERS_JSON_REDIRECT_CHAIN_INVALID', $canonical, $finalUrl, $response->status(), $response->header('Content-Type'), null);
                }
            }
        } catch (\Throwable $exception) {
            return $this->persist(false, 'SELLERS_JSON_CANONICAL_ORIGIN_UNREACHABLE', $canonical, $finalUrl, null, null, null, $exception->getMessage());
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        $payloadHash = hash('sha256', $response->body());
        if (! $response->successful()) {
            return $this->persist(false, 'SELLERS_JSON_CANONICAL_HTTP_ERROR', $canonical, $finalUrl, $response->status(), $response->header('Content-Type'), $payloadHash);
        }
        if (! str_starts_with($contentType, 'application/json')) {
            return $this->persist(false, 'SELLERS_JSON_CANONICAL_CONTENT_TYPE_INVALID', $canonical, $finalUrl, $response->status(), $response->header('Content-Type'), $payloadHash);
        }
        if (! hash_equals($expectedHash, $payloadHash)) {
            return $this->persist(false, 'SELLERS_JSON_CANONICAL_PAYLOAD_MISMATCH', $canonical, $finalUrl, $response->status(), $response->header('Content-Type'), $payloadHash);
        }

        return $this->persist(true, 'VERIFIED', $canonical, $finalUrl, $response->status(), $response->header('Content-Type'), $payloadHash);
    }

    /** @return array{verified: bool, code: string, checked_at: ?string} */
    public function readiness(): array
    {
        $canonical = (string) config('supply-chain.canonical_sellers_json_url');
        $expectedHash = hash('sha256', $this->artifacts->sellersJson());
        $check = SupplyChainOriginCheck::query()
            ->where('artifact', self::ARTIFACT_SELLERS_JSON)
            ->where('canonical_url', $canonical)
            ->latest('checked_at')->first();
        $verified = $check !== null
            && $check->status === 'VERIFIED'
            && hash_equals($expectedHash, (string) $check->payload_sha256);

        return [
            'verified' => $verified,
            'code' => $verified ? 'VERIFIED' : 'HORUS_SELLERS_JSON_PUBLIC_ORIGIN_UNVERIFIED',
            'checked_at' => $check?->checked_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function persist(
        bool $verified,
        string $code,
        string $canonical,
        ?string $finalUrl,
        ?int $httpStatus,
        ?string $contentType,
        ?string $payloadHash,
        ?string $message = null,
    ): array {
        $check = SupplyChainOriginCheck::create([
            'artifact' => self::ARTIFACT_SELLERS_JSON,
            'canonical_url' => $canonical,
            'final_url' => $finalUrl,
            'status' => $verified ? 'VERIFIED' : 'FAILED',
            'http_status' => $httpStatus,
            'content_type' => $contentType,
            'payload_sha256' => $payloadHash,
            'error_code' => $verified ? null : $code,
            'error_message' => $verified ? null : mb_substr((string) $message, 0, 1000),
            'checked_at' => now(),
        ]);

        return [
            'verified' => $verified,
            'code' => $code,
            'canonical_url' => $canonical,
            'final_url' => $finalUrl,
            'http_status' => $httpStatus,
            'content_type' => $contentType,
            'payload_sha256' => $payloadHash,
            'checked_at' => $check->checked_at?->toIso8601String(),
        ];
    }
}
