<?php

namespace Tests\Feature;

use App\Models\StaticDeliveryBatch;
use App\Services\StaticDelivery\Contracts\StaticDeliveryDriverInterface;
use App\Services\StaticDelivery\Data\StaticDeliverySnapshot;
use App\Services\StaticDelivery\Drivers\ExternalPagesSyncDriver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExternalPagesSyncDriverTest extends TestCase
{
    public function test_missing_server_github_credential_uses_external_sync_instead_of_failing_delivery(): void
    {
        config([
            'static-delivery.driver' => 'cloudflare-pages-pipeline',
            'static-delivery.cloudflare.github_token_reference' => 'env:HORUS_TEST_MISSING_EDGE_TOKEN',
        ]);
        putenv('HORUS_TEST_MISSING_EDGE_TOKEN');

        $this->assertInstanceOf(ExternalPagesSyncDriver::class, app(StaticDeliveryDriverInterface::class));
    }

    public function test_external_sync_waits_for_and_confirms_the_exact_public_manifest(): void
    {
        config(['static-delivery.external_sync.manifest_url' => 'https://cdn.example.test/delivery-manifest.json']);
        $hash = str_repeat('a', 64);
        $driver = app(ExternalPagesSyncDriver::class);
        $batch = new StaticDeliveryBatch(['manifest_hash' => $hash]);
        $submitted = $driver->deliver(new StaticDeliverySnapshot([], $hash, 0, false), $batch);

        $this->assertFalse($submitted->confirmedDeployed);
        $this->assertSame('manifest:'.$hash, $submitted->remoteId);

        Http::fake(['https://cdn.example.test/delivery-manifest.json*' => Http::response(['manifestHash' => $hash])]);
        $confirmed = $driver->probe($batch);

        $this->assertTrue($confirmed?->confirmedDeployed);
        $this->assertSame('manifest:'.$hash, $confirmed?->remoteId);
    }

    public function test_external_sync_does_not_confirm_a_stale_or_unavailable_manifest(): void
    {
        config(['static-delivery.external_sync.manifest_url' => 'https://cdn.example.test/delivery-manifest.json']);
        $batch = new StaticDeliveryBatch(['manifest_hash' => str_repeat('a', 64)]);
        $driver = app(ExternalPagesSyncDriver::class);

        Http::fake(['https://cdn.example.test/delivery-manifest.json*' => Http::response(['manifestHash' => str_repeat('b', 64)])]);
        $this->assertNull($driver->probe($batch));

        Http::fake(['https://cdn.example.test/delivery-manifest.json*' => Http::response([], 503)]);
        $this->assertNull($driver->probe($batch));
    }
}
