<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Models\Site;
use App\Models\StaticDeliveryBatch;
use App\Models\StaticDeliveryItem;
use App\Services\StaticDelivery\Contracts\StaticDeliveryDriverInterface;
use App\Services\StaticDelivery\Data\StaticDeliverySnapshot;
use App\Services\StaticDelivery\Drivers\ExternalPagesSyncDriver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExternalPagesSyncDriverTest extends TestCase
{
    private string $confirmationPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->confirmationPath = storage_path('framework/testing/confirmed-manifest-'.bin2hex(random_bytes(4)));
        config(['static-delivery.external_sync.confirmation_path' => $this->confirmationPath]);
    }

    protected function tearDown(): void
    {
        @unlink($this->confirmationPath);
        parent::tearDown();
    }

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

        Http::fake(['https://cdn.example.test/delivery-manifest.json*' => Http::response(['manifestHash' => $hash, 'files' => []])]);
        $confirmed = $driver->probe($batch);

        $this->assertTrue($confirmed?->confirmedDeployed);
        $this->assertSame('manifest:'.$hash, $confirmed?->remoteId);
    }

    public function test_external_sync_does_not_confirm_a_stale_or_unavailable_manifest(): void
    {
        config(['static-delivery.external_sync.manifest_url' => 'https://cdn.example.test/delivery-manifest.json']);
        $batch = new StaticDeliveryBatch(['manifest_hash' => str_repeat('a', 64)]);
        $driver = app(ExternalPagesSyncDriver::class);

        Http::fake(['https://cdn.example.test/delivery-manifest.json*' => Http::response(['manifestHash' => str_repeat('b', 64), 'files' => []])]);
        $this->assertNull($driver->probe($batch));

        Http::fake(['https://cdn.example.test/delivery-manifest.json*' => Http::response([], 503)]);
        $this->assertNull($driver->probe($batch));
    }

    public function test_control_plane_marker_never_bypasses_public_edge_verification(): void
    {
        config(['static-delivery.external_sync.manifest_url' => 'https://cdn.example.test/delivery-manifest.json']);
        $hash = str_repeat('c', 64);
        file_put_contents($this->confirmationPath, $hash."\n");
        Http::fake(['https://cdn.example.test/delivery-manifest.json*' => Http::response([], 503)]);

        $confirmed = app(ExternalPagesSyncDriver::class)
            ->probe(new StaticDeliveryBatch(['manifest_hash' => $hash]));

        $this->assertNull($confirmed);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://cdn.example.test/delivery-manifest.json'));
    }

    public function test_external_sync_confirms_changed_site_manifest_alias_and_immutable_config_are_public(): void
    {
        config(['static-delivery.external_sync.manifest_url' => 'https://cdn.example.test/delivery-manifest.json']);
        $hash = str_repeat('d', 64);
        $siteKey = 'hm_test1234567890';
        $configBody = '{"configVersion":1,"siteKey":"'.$siteKey.'"}';
        $checksum = hash('sha256', $configBody);
        $immutablePath = "configs/{$siteKey}/production.v1.".substr($checksum, 0, 16).'.json';
        $siteManifestBody = json_encode([
            'siteKey' => $siteKey,
            'generatedAt' => '2026-09-16T00:00:00+00:00',
            'environments' => [
                'production' => [
                    'version' => 1,
                    'path' => '/'.$immutablePath,
                    'sha256' => $checksum,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $siteManifestHash = hash('sha256', $siteManifestBody);
        $rootManifest = [
            'manifestHash' => $hash,
            'files' => [
                "configs/{$siteKey}/manifest.json" => $siteManifestHash,
                $immutablePath => $checksum,
                "configs/{$siteKey}/production.json" => $checksum,
            ],
        ];

        $site = new Site(['public_key' => $siteKey]);
        $item = new StaticDeliveryItem([
            'environment' => ConfigEnvironment::Production,
            'checksum' => $checksum,
        ]);
        $item->setRelation('site', $site);
        $batch = new StaticDeliveryBatch(['manifest_hash' => $hash]);
        $batch->setRelation('items', collect([$item]));

        $siteManifestMissing = false;
        Http::fake(function ($request) use (&$siteManifestMissing, $rootManifest, $siteKey, $siteManifestBody, $immutablePath, $configBody) {
            $url = $request->url();
            if (str_starts_with($url, 'https://cdn.example.test/delivery-manifest.json')) {
                return Http::response($rootManifest);
            }
            if (str_starts_with($url, "https://cdn.example.test/configs/{$siteKey}/manifest.json")) {
                return $siteManifestMissing
                    ? Http::response([], 404)
                    : Http::response($siteManifestBody, 200, ['Content-Type' => 'application/json']);
            }
            if (str_starts_with($url, "https://cdn.example.test/{$immutablePath}")) {
                return Http::response($configBody, 200, ['Content-Type' => 'application/json']);
            }
            if (str_starts_with($url, "https://cdn.example.test/configs/{$siteKey}/production.json")) {
                return Http::response($configBody, 200, ['Content-Type' => 'application/json']);
            }

            return Http::response([], 404);
        });

        $this->assertTrue(app(ExternalPagesSyncDriver::class)->probe($batch)?->confirmedDeployed);

        $siteManifestMissing = true;
        $this->assertNull(app(ExternalPagesSyncDriver::class)->probe($batch));
    }
}
