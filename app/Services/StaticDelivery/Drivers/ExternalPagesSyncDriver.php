<?php

namespace App\Services\StaticDelivery\Drivers;

use App\Models\StaticDeliveryBatch;
use App\Services\StaticDelivery\Contracts\StaticDeliveryDriverInterface;
use App\Services\StaticDelivery\Contracts\StaticDeliveryStatusProbeInterface;
use App\Services\StaticDelivery\Data\StaticDeliveryResult;
use App\Services\StaticDelivery\Data\StaticDeliverySnapshot;
use App\Services\StaticDelivery\Exceptions\StaticDeliveryException;
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

        $url = $this->manifestUrl();
        $response = Http::acceptJson()
            ->connectTimeout(max(1, (int) config('static-delivery.external_sync.connect_timeout', 5)))
            ->timeout(max(1, (int) config('static-delivery.external_sync.timeout', 20)))
            ->get($url, ['expected' => $expected]);
        if (! $response->successful()) {
            return null;
        }

        $published = (string) data_get($response->json(), 'manifestHash');
        if (! preg_match('/^[a-f0-9]{64}$/', $published) || ! hash_equals($expected, $published)) {
            return null;
        }

        return new StaticDeliveryResult(
            remoteId: 'manifest:'.$published,
            remoteUrl: $url,
            confirmedDeployed: true,
            metadata: ['manifest_hash' => $published],
        );
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
}
