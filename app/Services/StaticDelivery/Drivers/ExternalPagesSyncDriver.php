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

        // The local confirmation marker is deliberately not sufficient proof. A
        // previous sync may have written the marker while a custom-domain edge
        // later lost or never exposed one of the committed config artifacts.
        // Always prove the public edge before marking a batch as deployed.
        $response = $this->request($this->manifestUrl(), ['expected' => $expected]);
        if (! $response->successful()) {
            return null;
        }

        $manifest = $response->json();
        $published = is_array($manifest) ? (string) ($manifest['manifestHash'] ?? '') : '';
        if (! preg_match('/^[a-f0-9]{64}$/', $published) || ! hash_equals($expected, $published)) {
            return null;
        }

        if (! $this->batchArtifactsArePublic($batch, is_array($manifest) ? $manifest : [])) {
            return null;
        }

        return $this->confirmedResult($published);
    }

    private function batchArtifactsArePublic(StaticDeliveryBatch $batch, array $rootManifest): bool
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

            $siteManifestResponse = $this->request($this->publicUrl($manifestPath), ['expected' => $checksum]);
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

            $immutableResponse = $this->request($this->publicUrl($immutablePath), ['expected' => $checksum]);
            if (! $immutableResponse->successful() || ! hash_equals($checksum, hash('sha256', $immutableResponse->body()))) {
                return false;
            }

            $aliasPath = "configs/{$siteKey}/{$environment}.json";
            if (! isset($rootFiles[$aliasPath]) || ! hash_equals($checksum, (string) $rootFiles[$aliasPath])) {
                return false;
            }
            $aliasResponse = $this->request($this->publicUrl($aliasPath), ['expected' => $checksum]);
            if (! $aliasResponse->successful() || ! hash_equals($checksum, hash('sha256', $aliasResponse->body()))) {
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

    private function publicUrl(string $path): string
    {
        $manifestUrl = $this->manifestUrl();
        $suffix = '/delivery-manifest.json';
        if (! str_ends_with($manifestUrl, $suffix)) {
            throw new StaticDeliveryException('MANIFEST_URL_INVALID', 'External static delivery manifest URL must end with /delivery-manifest.json.');
        }
        $base = substr($manifestUrl, 0, -strlen($suffix));

        return rtrim($base, '/').'/'.ltrim($path, '/');
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
