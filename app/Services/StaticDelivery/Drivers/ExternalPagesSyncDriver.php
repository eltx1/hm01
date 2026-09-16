<?php

namespace App\Services\StaticDelivery\Drivers;

use App\Models\StaticDeliveryBatch;
use App\Services\StaticDelivery\Contracts\StaticDeliveryDriverInterface;
use App\Services\StaticDelivery\Contracts\StaticDeliveryStatusProbeInterface;
use App\Services\StaticDelivery\Data\StaticDeliveryResult;
use App\Services\StaticDelivery\Data\StaticDeliverySnapshot;
use App\Services\StaticDelivery\Exceptions\StaticDeliveryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

final class ExternalPagesSyncDriver implements StaticDeliveryDriverInterface, StaticDeliveryStatusProbeInterface
{
    public function name(): string
    {
        return 'external-pages-sync';
    }

    public function deliver(StaticDeliverySnapshot $snapshot, StaticDeliveryBatch $batch): StaticDeliveryResult
    {
        return new StaticDeliveryResult(
            remoteId: 'manifest:'.$snapshot->manifestHash,
            remoteUrl: $this->manifestUrl(),
            confirmedDeployed: false,
            metadata: ['manifest_hash' => $snapshot->manifestHash],
        );
    }

    public function probe(StaticDeliveryBatch $batch): ?StaticDeliveryResult
    {
        $expected = (string) $batch->manifest_hash;
        if (! preg_match('/^[a-f0-9]{64}$/', $expected)) {
            throw new StaticDeliveryException('MANIFEST_HASH_INVALID', 'Static delivery batch does not contain a valid manifest hash.');
        }

        // The production workflow writes this marker only after the complete
        // snapshot has passed exact Pages/CDN verification. The marker is
        // necessary, but never sufficient: stale markers must not hide missing
        // site files or a broken Traffic Gate custom domain.
        $confirmed = $this->confirmedManifest();
        if ($confirmed === null || ! hash_equals($expected, $confirmed)) {
            return null;
        }

        $canonicalManifest = $this->publicManifest($this->manifestUrl(), $expected);
        if ($canonicalManifest === null
            || ! $this->batchArtifactsArePublic($batch, $canonicalManifest, $this->manifestBaseUrl())) {
            return null;
        }

        // Traffic Gate executes on verify.horusmedia.net and performs its own
        // same-origin configuration read. Prove that custom domain independently
        // instead of assuming canonical CDN parity automatically propagates there.
        $gateManifest = $this->publicManifest($this->gateManifestUrl(), $expected);
        if ($gateManifest === null
            || ! $this->gateRuntimeIsPublic($gateManifest)
            || ! $this->batchArtifactsArePublic($batch, $gateManifest, $this->gateOrigin())) {
            return null;
        }

        return $this->confirmedResult($expected);
    }

    /** @return array<string, mixed>|null */
    private function publicManifest(string $url, string $expected): ?array
    {
        $response = $this->request($url, ['expected' => $expected]);
        if (! $response->successful()) {
            return null;
        }

        $manifest = $response->json();
        $published = is_array($manifest) ? (string) ($manifest['manifestHash'] ?? '') : '';
        if (! preg_match('/^[a-f0-9]{64}$/', $published) || ! hash_equals($expected, $published)) {
            return null;
        }

        return $manifest;
    }

    private function batchArtifactsArePublic(StaticDeliveryBatch $batch, array $rootManifest, string $baseUrl): bool
    {
        $items = $this->batchItems($batch);
        if ($items->isEmpty()) {
            return true;
        }

        $rootFiles = $rootManifest['files'] ?? null;
        if (! is_array($rootFiles)) {
            return false;
        }

        foreach ($items as $item) {
            $site = $item->relationLoaded('site') ? $item->getRelation('site') : $item->site;
            if (! $site || blank($site->public_key)) {
                return false;
            }

            $siteKey = (string) $site->public_key;
            $environment = strtolower($item->environment->value);
            $checksum = (string) $item->checksum;
            if (! preg_match('/^[a-f0-9]{64}$/', $checksum)) {
                return false;
            }

            $manifestPath = "configs/{$siteKey}/manifest.json";
            $expectedManifestHash = $rootFiles[$manifestPath] ?? null;
            if (! is_string($expectedManifestHash) || ! preg_match('/^[a-f0-9]{64}$/', $expectedManifestHash)) {
                return false;
            }

            $siteManifestResponse = $this->request($this->publicUrl($baseUrl, $manifestPath), ['expected' => $checksum]);
            if (! $siteManifestResponse->successful()
                || ! hash_equals($expectedManifestHash, hash('sha256', $siteManifestResponse->body()))) {
                return false;
            }

            $siteManifest = $siteManifestResponse->json();
            $entry = is_array($siteManifest) ? data_get($siteManifest, "environments.{$environment}") : null;
            if (! is_array($entry) || ! hash_equals($checksum, (string) ($entry['sha256'] ?? ''))) {
                return false;
            }

            $immutablePath = ltrim((string) ($entry['path'] ?? ''), '/');
            $expectedPrefix = "configs/{$siteKey}/{$environment}.v";
            if (! str_starts_with($immutablePath, $expectedPrefix) || str_contains($immutablePath, '..')) {
                return false;
            }
            if (! isset($rootFiles[$immutablePath]) || ! hash_equals($checksum, (string) $rootFiles[$immutablePath])) {
                return false;
            }

            $immutableResponse = $this->request($this->publicUrl($baseUrl, $immutablePath), ['expected' => $checksum]);
            if (! $immutableResponse->successful() || ! hash_equals($checksum, hash('sha256', $immutableResponse->body()))) {
                return false;
            }

            $aliasPath = "configs/{$siteKey}/{$environment}.json";
            if (! isset($rootFiles[$aliasPath]) || ! hash_equals($checksum, (string) $rootFiles[$aliasPath])) {
                return false;
            }
            $aliasResponse = $this->request($this->publicUrl($baseUrl, $aliasPath), ['expected' => $checksum]);
            if (! $aliasResponse->successful() || ! hash_equals($checksum, hash('sha256', $aliasResponse->body()))) {
                return false;
            }
        }

        return true;
    }

    private function gateRuntimeIsPublic(array $rootManifest): bool
    {
        $rootFiles = $rootManifest['files'] ?? null;
        if (! is_array($rootFiles)) {
            return false;
        }

        $javascriptPath = 'assets/traffic-gate/horus-traffic-gate.js';
        $expectedJavascriptHash = $rootFiles[$javascriptPath] ?? null;
        if (! is_string($expectedJavascriptHash) || ! preg_match('/^[a-f0-9]{64}$/', $expectedJavascriptHash)) {
            return false;
        }

        $javascript = $this->request($this->publicUrl($this->gateOrigin(), $javascriptPath));
        if (! $javascript->successful()
            || ! hash_equals($expectedJavascriptHash, hash('sha256', $javascript->body()))) {
            return false;
        }

        // Cloudflare Web Analytics may append a beacon to HTML at the edge, so
        // do not compare the gate document byte-for-byte. Verify the Horus gate
        // contract and the enforced CSP that actually protects Turnstile.
        $page = $this->request($this->publicUrl($this->gateOrigin(), 'traffic-gate/'));
        if (! $page->successful()
            || ! str_contains($page->body(), '<title>Horus Client Traffic Gate</title>')
            || ! str_contains($page->body(), '/assets/traffic-gate/horus-traffic-gate.js')) {
            return false;
        }

        $csp = (string) $page->header('Content-Security-Policy');
        foreach ([
            "script-src 'self' https://challenges.cloudflare.com",
            'frame-src https://challenges.cloudflare.com',
            "connect-src 'self' https://challenges.cloudflare.com",
            'frame-ancestors https:',
        ] as $requiredDirective) {
            if (! str_contains($csp, $requiredDirective)) {
                return false;
            }
        }

        return true;
    }

    private function batchItems(StaticDeliveryBatch $batch): Collection
    {
        if ($batch->relationLoaded('items')) {
            return collect($batch->getRelation('items'));
        }
        if (! $batch->exists) {
            return collect();
        }

        return $batch->items()->with('site:id,public_key')->get();
    }

    private function request(string $url, array $query = [])
    {
        return Http::acceptJson()
            ->connectTimeout(max(1, (int) config('static-delivery.external_sync.connect_timeout', 5)))
            ->timeout(max(1, (int) config('static-delivery.external_sync.timeout', 20)))
            ->get($url, $query);
    }

    private function manifestUrl(): string
    {
        $url = (string) config('static-delivery.external_sync.manifest_url');
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || blank($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass'])) {
            throw new StaticDeliveryException('MANIFEST_URL_INVALID', 'External static delivery manifest URL must be a public HTTPS URL.');
        }

        return $url;
    }

    private function manifestBaseUrl(): string
    {
        $manifestUrl = $this->manifestUrl();
        $suffix = '/delivery-manifest.json';
        if (! str_ends_with($manifestUrl, $suffix)) {
            throw new StaticDeliveryException('MANIFEST_URL_INVALID', 'External static delivery manifest URL must end with /delivery-manifest.json.');
        }

        return substr($manifestUrl, 0, -strlen($suffix));
    }

    private function gateOrigin(): string
    {
        $url = rtrim((string) config('traffic_gate.origin'), '/');
        $parts = parse_url($url);
        $path = (string) ($parts['path'] ?? '');
        if (($parts['scheme'] ?? null) !== 'https'
            || blank($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['port'])
            || ! in_array($path, ['', '/'], true)) {
            throw new StaticDeliveryException('GATE_ORIGIN_INVALID', 'Traffic Gate origin must be a bare public HTTPS origin.');
        }

        return $url;
    }

    private function gateManifestUrl(): string
    {
        return $this->gateOrigin().'/delivery-manifest.json';
    }

    private function publicUrl(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function confirmedManifest(): ?string
    {
        $path = (string) config('static-delivery.external_sync.confirmation_path');
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $hash = trim((string) file_get_contents($path));

        return preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : null;
    }

    private function confirmedResult(string $hash): StaticDeliveryResult
    {
        return new StaticDeliveryResult(
            remoteId: 'manifest:'.$hash,
            remoteUrl: $this->manifestUrl(),
            confirmedDeployed: true,
            metadata: ['manifest_hash' => $hash],
        );
    }
}
