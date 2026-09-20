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
    private const GATE_JS = "(() => { window.__horusGate = true; })();\n";
    private const GATE_PAGE = '<!doctype html><title>Horus Client Traffic Gate</title><script src="/assets/traffic-gate/horus-traffic-gate.js" defer></script>';
    private const GATE_CSP = "default-src 'none'; script-src 'self' https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com; connect-src 'self' https://challenges.cloudflare.com https://siteverify.horusmedia.net; frame-ancestors https:";

    private string $confirmationPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->confirmationPath = storage_path('framework/testing/confirmed-manifest-'.bin2hex(random_bytes(4)));
        config([
            'static-delivery.external_sync.confirmation_path' => $this->confirmationPath,
            'static-delivery.external_sync.manifest_url' => 'https://cdn.example.test/delivery-manifest.json',
            'traffic_gate.origin' => 'https://verify.example.test',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->confirmationPath);
        parent::tearDown();
    }

    public function test_missing_server_github_credential_does_not_silently_disable_active_automation(): void
    {
        config([
            'static-delivery.driver' => 'cloudflare-pages-pipeline',
            'static-delivery.cloudflare.github_token_reference' => 'env:HORUS_TEST_MISSING_EDGE_TOKEN',
        ]);
        putenv('HORUS_TEST_MISSING_EDGE_TOKEN');

        $driver = app(StaticDeliveryDriverInterface::class);
        $this->assertInstanceOf(\App\Services\StaticDelivery\Drivers\CloudflarePagesPipelineDriver::class, $driver);
        $this->expectException(\App\Services\StaticDelivery\Exceptions\StaticDeliveryException::class);
        $driver->deliver(new StaticDeliverySnapshot([], str_repeat('a', 64), 0, false), new StaticDeliveryBatch);
    }

    public function test_external_sync_requires_workflow_confirmation_then_confirms_exact_public_edges(): void
    {
        $hash = str_repeat('a', 64);
        $driver = app(ExternalPagesSyncDriver::class);
        $batch = new StaticDeliveryBatch(['manifest_hash' => $hash]);
        $submitted = $driver->deliver(new StaticDeliverySnapshot([], $hash, 0, false), $batch);

        $this->assertFalse($submitted->confirmedDeployed);
        $this->assertSame('manifest:'.$hash, $submitted->remoteId);

        $rootManifest = $this->rootManifest($hash);
        $this->fakeHealthyEdges($rootManifest);

        $this->assertNull($driver->probe($batch), 'Public files alone must not bypass the production workflow confirmation marker.');

        file_put_contents($this->confirmationPath, $hash."\n");
        $confirmed = $driver->probe($batch);

        $this->assertTrue($confirmed?->confirmedDeployed);
        $this->assertSame('manifest:'.$hash, $confirmed?->remoteId);
    }

    public function test_external_sync_does_not_confirm_a_stale_or_unavailable_manifest(): void
    {
        $hash = str_repeat('a', 64);
        file_put_contents($this->confirmationPath, $hash."\n");
        $batch = new StaticDeliveryBatch(['manifest_hash' => $hash]);
        $driver = app(ExternalPagesSyncDriver::class);

        Http::fake(['https://cdn.example.test/delivery-manifest.json*' => Http::response(['manifestHash' => str_repeat('b', 64), 'files' => []])]);
        $this->assertNull($driver->probe($batch));

        Http::fake(['https://cdn.example.test/delivery-manifest.json*' => Http::response([], 503)]);
        $this->assertNull($driver->probe($batch));
    }

    public function test_control_plane_marker_never_bypasses_public_edge_verification(): void
    {
        $hash = str_repeat('c', 64);
        file_put_contents($this->confirmationPath, $hash."\n");
        Http::fake(['https://cdn.example.test/delivery-manifest.json*' => Http::response([], 503)]);

        $confirmed = app(ExternalPagesSyncDriver::class)
            ->probe(new StaticDeliveryBatch(['manifest_hash' => $hash]));

        $this->assertNull($confirmed);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://cdn.example.test/delivery-manifest.json'));
    }

    public function test_external_sync_requires_changed_site_artifacts_on_cdn_and_traffic_gate_origin(): void
    {
        $hash = str_repeat('d', 64);
        file_put_contents($this->confirmationPath, $hash."\n");
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
        $rootManifest = $this->rootManifest($hash, [
            "configs/{$siteKey}/manifest.json" => $siteManifestHash,
            $immutablePath => $checksum,
            "configs/{$siteKey}/production.json" => $checksum,
        ]);

        $site = new Site(['public_key' => $siteKey]);
        $item = new StaticDeliveryItem([
            'environment' => ConfigEnvironment::Production,
            'checksum' => $checksum,
        ]);
        $item->setRelation('site', $site);
        $batch = new StaticDeliveryBatch(['manifest_hash' => $hash]);
        $batch->setRelation('items', collect([$item]));

        $gateSiteManifestMissing = false;
        Http::fake(function ($request) use (&$gateSiteManifestMissing, $rootManifest, $siteKey, $siteManifestBody, $immutablePath, $configBody) {
            $url = $request->url();
            if (str_starts_with($url, 'https://cdn.example.test/delivery-manifest.json')
                || str_starts_with($url, 'https://verify.example.test/delivery-manifest.json')) {
                return Http::response($rootManifest);
            }
            if (str_starts_with($url, 'https://verify.example.test/assets/traffic-gate/horus-traffic-gate.js')) {
                return Http::response(self::GATE_JS, 200, ['Content-Type' => 'application/javascript']);
            }
            if (str_starts_with($url, 'https://verify.example.test/traffic-gate/')) {
                return Http::response(self::GATE_PAGE, 200, ['Content-Security-Policy' => self::GATE_CSP]);
            }
            if (str_starts_with($url, "https://verify.example.test/configs/{$siteKey}/manifest.json")) {
                return $gateSiteManifestMissing
                    ? Http::response([], 404)
                    : Http::response($siteManifestBody, 200, ['Content-Type' => 'application/json']);
            }
            if (str_starts_with($url, "https://cdn.example.test/configs/{$siteKey}/manifest.json")) {
                return Http::response($siteManifestBody, 200, ['Content-Type' => 'application/json']);
            }
            if (str_starts_with($url, "https://cdn.example.test/{$immutablePath}")
                || str_starts_with($url, "https://verify.example.test/{$immutablePath}")
                || str_starts_with($url, "https://cdn.example.test/configs/{$siteKey}/production.json")
                || str_starts_with($url, "https://verify.example.test/configs/{$siteKey}/production.json")) {
                return Http::response($configBody, 200, ['Content-Type' => 'application/json']);
            }

            return Http::response([], 404);
        });

        $this->assertTrue(app(ExternalPagesSyncDriver::class)->probe($batch)?->confirmedDeployed);

        $gateSiteManifestMissing = true;
        $this->assertNull(app(ExternalPagesSyncDriver::class)->probe($batch));
    }

    private function rootManifest(string $hash, array $files = []): array
    {
        return [
            'manifestHash' => $hash,
            'files' => array_merge([
                'assets/traffic-gate/horus-traffic-gate.js' => hash('sha256', self::GATE_JS),
                'traffic-gate/index.html' => hash('sha256', self::GATE_PAGE),
            ], $files),
        ];
    }

    private function fakeHealthyEdges(array $rootManifest): void
    {
        Http::fake([
            'https://cdn.example.test/delivery-manifest.json*' => Http::response($rootManifest),
            'https://verify.example.test/delivery-manifest.json*' => Http::response($rootManifest),
            'https://verify.example.test/assets/traffic-gate/horus-traffic-gate.js*' => Http::response(self::GATE_JS, 200, ['Content-Type' => 'application/javascript']),
            'https://verify.example.test/traffic-gate/*' => Http::response(self::GATE_PAGE, 200, ['Content-Security-Policy' => self::GATE_CSP]),
        ]);
    }
}
