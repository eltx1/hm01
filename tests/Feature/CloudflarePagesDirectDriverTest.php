<?php

namespace Tests\Feature;

use App\Models\StaticDeliveryBatch;
use App\Services\StaticDelivery\Data\StaticDeliverySnapshot;
use App\Services\StaticDelivery\Drivers\CloudflarePagesDirectDriver;
use App\Services\StaticDelivery\Exceptions\StaticDeliveryException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflarePagesDirectDriverTest extends TestCase
{
    private string $credential;
    private const HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->credential = tempnam(sys_get_temp_dir(), 'cf-test-');
        file_put_contents($this->credential, 'existing-cloudflare-test-credential');
        config([
            'static-delivery.driver' => 'cloudflare-pages-direct',
            'static-delivery.cloudflare.api_token_reference' => 'file:'.$this->credential,
            'static-delivery.cloudflare.account_id' => str_repeat('a', 32),
            'static-delivery.cloudflare.project' => 'horus-media-cdn',
            'static-delivery.cloudflare.production_branch' => 'main',
            'static-delivery.cloudflare.dry_run' => false,
            'static-delivery.external_sync.manifest_url' => 'https://cdn.example.test/delivery-manifest.json',
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        @unlink($this->credential);
        parent::tearDown();
    }

    public function test_snapshot_uses_existing_cloudflare_credential_and_uploads_static_assets_and_headers(): void
    {
        $this->fakeProvider();
        $result = app(CloudflarePagesDirectDriver::class)->deliver($this->snapshot(), $this->batch());
        $this->assertSame('deployment-123', $result->remoteId);
        $this->assertFalse($result->confirmedDeployed);
        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/pages/assets/upload')) {
                return false;
            }
            $this->assertSame(['Bearer asset-jwt-test'], $request->header('Authorization'));
            $this->assertCount(1, $request->data());
            $this->assertSame('{}', base64_decode($request[0]['value']));
            $this->assertSame('application/json', $request[0]['metadata']['contentType']);
            $this->assertTrue($request[0]['base64']);

            return true;
        });
        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/deployments')) {
                return false;
            }
            $this->assertSame(['Bearer existing-cloudflare-test-credential'], $request->header('Authorization'));
            $this->assertStringContainsString('frame-ancestors https:', $request->body());
            $this->assertStringContainsString('/configs/test.json', $request->body());
            $this->assertStringNotContainsString('_routes.json', $request->body());
            $this->assertStringContainsString('Horus static manifest '.self::HASH, $request->body());

            return true;
        });
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'github.com'));
    }

    public function test_lost_response_reuses_latest_running_production_deployment_without_writes(): void
    {
        $this->fakeProvider([$this->deployment('active')]);
        $result = app(CloudflarePagesDirectDriver::class)->deliver($this->snapshot(), $this->batch());
        $this->assertSame('deployment-123', $result->remoteId);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_a_matching_older_deployment_does_not_suppress_a_needed_publish(): void
    {
        $newer = $this->deployment('success');
        $newer['deployment_trigger']['metadata']['commit_message'] = 'Different snapshot';
        $this->fakeProvider([$newer, $this->deployment('success')]);
        app(CloudflarePagesDirectDriver::class)->deliver($this->snapshot(), $this->batch());
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/deployments'));
    }

    public function test_failed_submission_can_retry_and_cached_assets_are_not_reuploaded(): void
    {
        $this->fakeProvider([$this->deployment('failure')], cached: true);
        app(CloudflarePagesDirectDriver::class)->deliver($this->snapshot(), $this->batch());
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/pages/assets/upload'));
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/deployments'));
    }

    public function test_dry_run_has_no_network_side_effects(): void
    {
        config(['static-delivery.cloudflare.dry_run' => true]);
        try {
            app(CloudflarePagesDirectDriver::class)->deliver($this->snapshot(), $this->batch());
            $this->fail('Expected dry-run refusal');
        } catch (StaticDeliveryException $exception) {
            $this->assertSame('DRY_RUN_ONLY', $exception->category);
        }
        Http::assertNothingSent();
    }

    public function test_provider_rejection_redacts_the_body_and_does_not_fall_back_to_passive_success(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'errors' => ['secret-do-not-print']], 403)]);
        try {
            app(CloudflarePagesDirectDriver::class)->deliver($this->snapshot(), $this->batch());
            $this->fail('Expected rejection');
        } catch (StaticDeliveryException $exception) {
            $this->assertSame('CLOUDFLARE_API_REJECTED', $exception->category);
            $this->assertStringNotContainsString('secret-do-not-print', $exception->getMessage());
        }
    }

    public function test_production_branch_mismatch_is_rejected_before_upload(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'result' => ['production_branch' => 'different']])]);
        try {
            app(CloudflarePagesDirectDriver::class)->deliver($this->snapshot(), $this->batch());
            $this->fail('Expected branch mismatch');
        } catch (StaticDeliveryException $exception) {
            $this->assertSame('PRODUCTION_BRANCH_MISMATCH', $exception->category);
        }
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_successful_cloudflare_deployment_with_stale_public_cdn_is_not_confirmed(): void
    {
        $this->fakeProvider();
        $this->assertNull(app(CloudflarePagesDirectDriver::class)->probe($this->batch()));
    }

    public function test_preview_or_wrong_snapshot_is_never_confirmed(): void
    {
        $deployment = $this->deployment('success');
        $deployment['environment'] = 'preview';
        Http::fake(['*' => Http::response(['success' => true, 'result' => $deployment])]);
        $this->expectExceptionMessage('does not match the expected production snapshot');
        app(CloudflarePagesDirectDriver::class)->probe($this->batch());
    }

    private function snapshot(): StaticDeliverySnapshot
    {
        return new StaticDeliverySnapshot(['configs/test.json' => '{}', '_headers' => 'frame-ancestors https:', '_routes.json' => '{}'], self::HASH, 40, false);
    }

    private function batch(): StaticDeliveryBatch
    {
        return new StaticDeliveryBatch(['driver' => 'cloudflare-pages-direct', 'manifest_hash' => self::HASH, 'remote_deployment_id' => 'deployment-123']);
    }

    private function deployment(string $status): array
    {
        return ['id' => 'deployment-123', 'environment' => 'production',
            'deployment_trigger' => ['metadata' => ['commit_message' => 'Horus static manifest '.self::HASH]],
            'latest_stage' => ['name' => 'deploy', 'status' => $status]];
    }

    private function fakeProvider(array $deployments = [], bool $cached = false): void
    {
        Http::fake(function ($request) use ($deployments, $cached) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_starts_with($request->url(), 'https://cdn.example.test/')) {
                return Http::response(['manifestHash' => str_repeat('b', 64)]);
            }
            $result = match (true) {
                str_ends_with($path, '/horus-media-cdn') => ['production_branch' => 'main'],
                str_ends_with($path, '/upload-token') => ['jwt' => 'asset-jwt-test'],
                str_ends_with($path, '/check-missing') => $cached ? [] : $request['hashes'],
                str_ends_with($path, '/deployments') && $request->method() === 'GET' => $deployments,
                str_ends_with($path, '/deployments'), str_ends_with($path, '/deployment-123') => $this->deployment('success'),
                str_ends_with($path, '/upload'), str_ends_with($path, '/upsert-hashes') => [],
                default => throw new \RuntimeException('Unexpected request: '.$path),
            };

            return Http::response(['success' => true, 'result' => $result]);
        });
    }
}
