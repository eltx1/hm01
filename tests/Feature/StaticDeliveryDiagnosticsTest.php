<?php

namespace Tests\Feature;

use App\Models\StaticDeliveryBatch;
use App\Services\StaticDelivery\StaticDeliveryDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StaticDeliveryDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_provider_and_public_mismatch_without_writes_or_exposing_provider_secrets(): void
    {
        $credential = tempnam(sys_get_temp_dir(), 'edge-diagnostic-');
        file_put_contents($credential, 'secret-provider-token');
        config([
            'static-delivery.cloudflare.api_token_reference' => 'file:'.$credential,
            'static-delivery.cloudflare.account_id' => str_repeat('a', 32),
            'static-delivery.cloudflare.project' => 'horus-media-cdn',
            'static-delivery.external_sync.manifest_url' => 'https://cdn.example.test/delivery-manifest.json',
            'traffic_gate.origin' => 'https://verify.example.test',
        ]);
        $batch = StaticDeliveryBatch::create(['driver' => 'cloudflare-pages-direct', 'status' => 'UPLOADING',
            'priority' => 'NORMAL', 'trigger' => 'SCHEDULED', 'manifest_hash' => str_repeat('a', 64),
            'remote_deployment_id' => 'deployment-123', 'error_message' => 'private-error-must-not-leak']);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.cloudflare.com')) {
                return Http::response(['success' => true, 'result' => ['id' => 'deployment-123',
                    'environment' => 'production', 'latest_stage' => ['name' => 'deploy', 'status' => 'success'],
                    'deployment_trigger' => ['metadata' => ['commit_message' => 'Horus static manifest '.str_repeat('a', 64)]],
                    'secret' => 'provider-response-must-not-leak']]);
            }

            return Http::response(['manifestHash' => str_repeat('b', 64), 'private' => 'response-body-must-not-leak']);
        });
        try {
            $report = app(StaticDeliveryDiagnostics::class)->collect();
            $this->assertFalse($report['cdn']['matches_latest_batch']);
            $this->assertSame(str_repeat('b', 64), $report['gate']['manifest_hash']);
            $this->assertSame('success', $report['provider_batch']['deployment']['status']);
            $this->assertSame('UPLOADING', $batch->fresh()->status->value);
            $this->assertSame(0, $batch->fresh()->attempts);
            $this->assertDatabaseCount('static_delivery_batches', 1);
            $encoded = json_encode($report);
            foreach (['secret-provider-token', 'must-not-leak', $credential] as $secret) {
                $this->assertStringNotContainsString($secret, $encoded);
            }
            Http::assertSentCount(4);
            Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        } finally {
            unlink($credential);
        }
    }
}
