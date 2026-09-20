<?php

namespace Tests\Feature;

use App\Models\StaticDeliveryBatch;
use App\Services\StaticDelivery\Data\StaticDeliverySnapshot;
use App\Services\StaticDelivery\Drivers\CloudflarePagesDirectDriver;
use App\Services\StaticDelivery\Exceptions\StaticDeliveryException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_an_unrelated_running_deployment_blocks_a_competing_upload(): void
    {
        // A completed initialization stage is not a completed deployment.
        $other = $this->deployment('success');
        $other['latest_stage']['name'] = 'initialize';
        $other['deployment_trigger']['metadata']['commit_message'] = 'Another deployment';
        $this->fakeProvider([$other]);
        try {
            app(CloudflarePagesDirectDriver::class)->deliver($this->snapshot(), $this->batch());
            $this->fail('Expected an in-flight deployment guard');
        } catch (StaticDeliveryException $exception) {
            $this->assertSame('PAGES_DEPLOYMENT_BUSY', $exception->category);
        }
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_confirmation_requires_provider_success_and_real_gate_contract(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'cf-confirm-');
        config(['traffic_gate.origin' => 'https://verify.example.test', 'static-delivery.external_sync.confirmation_path' => $marker]);
        $js = 'gate-runtime-test';
        $gateHealthy = false;
        Http::fake(function ($request) use ($js, &$gateHealthy) {
            if (str_starts_with($request->url(), 'https://api.cloudflare.com/')) {
                return Http::response(['success' => true, 'result' => $this->deployment('success')]);
            }
            if (str_contains($request->url(), '/delivery-manifest.json')) {
                return Http::response(['manifestHash' => self::HASH, 'files' => ['assets/traffic-gate/horus-traffic-gate.js' => hash('sha256', $js)]]);
            }
            if (str_ends_with($request->url(), '/assets/traffic-gate/horus-traffic-gate.js')) {
                return Http::response($js);
            }

            return Http::response('<title>Horus Client Traffic Gate</title><script src="/assets/traffic-gate/horus-traffic-gate.js"></script>', 200, [
                'Content-Security-Policy' => $gateHealthy ? "script-src 'self' https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com; connect-src 'self' https://challenges.cloudflare.com https://siteverify.horusmedia.net; frame-ancestors https:" : '',
            ]);
        });
        try {
            $driver = app(CloudflarePagesDirectDriver::class);
            $this->assertNull($driver->probe($this->batch()));
            $this->assertSame('', file_get_contents($marker));
            $gateHealthy = true;
            $this->assertTrue($driver->probe($this->batch())?->confirmedDeployed);
            $this->assertSame(self::HASH, trim(file_get_contents($marker)));
        } finally {
            @unlink($marker);
        }
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

    public function test_unconfirmed_passive_batch_enters_normal_retry_after_driver_migration(): void
    {
        config(['static-delivery.external_sync.confirmation_path' => '/nonexistent/horus-test-marker']);
        $batch = $this->batch();
        $batch->driver = 'external-pages-sync';
        try {
            app(CloudflarePagesDirectDriver::class)->probe($batch);
            $this->fail('Expected migration to the active publisher');
        } catch (StaticDeliveryException $exception) {
            $this->assertSame('DELIVERY_DRIVER_CHANGED', $exception->category);
        }
        Http::assertNothingSent();
    }

    #[DataProvider('wafEvidenceCases')]
    public function test_waf_confirmation_requires_exact_current_pages_snapshot_and_active_domains(string $case, bool $confirmed): void
    {
        $id = '12345678-1234-1234-1234-123456789abc';
        $origin = 'https://12345678.horus-media-cdn.pages.dev';
        $js = 'immutable-gate-runtime';
        $page = '<title>Horus Client Traffic Gate</title><script src="/assets/traffic-gate/horus-traffic-gate.js"></script>';
        $files = ['hm-loader.js' => 'immutable-loader', 'health/delivery.json' => '{"schemaVersion":1,"status":"unknown","probeCount":0,"lastObservedAt":null}', 'assets/traffic-gate/horus-traffic-gate.js' => $js,
            'traffic-gate/index.html' => $page, '404.html' => 'not-found-page', 'index.html' => 'home-page'];
        ksort($files);
        $hashes = array_map(fn ($body) => hash('sha256', $body), $files);
        $input = '';
        foreach ($hashes as $path => $hash) {
            $input .= $path."\0".$hash."\n";
        }
        $hash = hash('sha256', $input);
        $manifest = ['manifestHash' => $hash, 'files' => $hashes];
        $batch = new StaticDeliveryBatch(['driver' => 'cloudflare-pages-direct', 'manifest_hash' => $hash,
            'remote_deployment_id' => $id]);
        $marker = tempnam(sys_get_temp_dir(), 'pages-proof-');
        config(['traffic_gate.origin' => 'https://verify.example.test', 'static-delivery.external_sync.confirmation_path' => $marker]);
        $deployment = ['id' => $id, 'environment' => 'production', 'url' => $origin,
            'latest_stage' => ['name' => 'deploy', 'status' => 'success'],
            'deployment_trigger' => ['metadata' => ['commit_message' => 'Horus static manifest '.$hash]]];
        if ($case === 'untrusted_url') {
            $deployment['url'] = 'https://untrusted.example.test';
        }
        Http::fake(function ($request) use ($case, $origin, $id, $deployment, $manifest, $files, $page) {
            $url = $request->url();
            $path = parse_url($url, PHP_URL_PATH);
            if (str_starts_with($url, 'https://api.cloudflare.com/')) {
                if (str_contains($path, '/domains/')) {
                    return Http::response(['success' => true, 'result' => ['name' => basename($path),
                        'status' => $case === 'inactive_domain' ? 'pending' : 'active']]);
                }
                $result = str_contains($path, '/deployments/') ? $deployment
                    : ['canonical_deployment' => ['id' => $case === 'old_deployment' ? 'other' : $id]];

                return Http::response(['success' => true, 'result' => $result]);
            }
            if (str_starts_with($url, $origin.'/')) {
                if ($path === '/delivery-manifest.json') {
                    $root = $manifest;
                    if ($case === 'forged_file_map') {
                        $root['files']['hm-loader.js'] = str_repeat('e', 64);
                    }

                    return Http::response($root);
                }
                if ($path === '/traffic-gate/') {
                    if ($case === 'canonical_redirect') {
                        return Http::response('', 308, ['Location' => 'https://untrusted.example.test/gate']);
                    }

                    return Http::response($case === 'corrupt_html' ? $page.'changed' : $page, 200, ['Content-Security-Policy' => $case === 'missing_csp' ? ''
                        : "script-src 'self' https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com; connect-src 'self' https://challenges.cloudflare.com https://siteverify.horusmedia.net; frame-ancestors https:"]);
                }
                if ($path === '/404' || $path === '/') {
                    return Http::response($path === '/404' ? $files['404.html'] : $files['index.html']);
                }
                if (str_ends_with($path, '.html')) {
                    return Http::response('', 308, ['Location' => $path === '/traffic-gate/index.html' ? '/traffic-gate/' : '/404']);
                }

                return Http::response($case === 'corrupt_loader' && $path === '/hm-loader.js'
                    ? 'corrupted' : ($files[ltrim($path, '/')] ?? ''), 200);
            }
            $status = match ($case) { 'not_found' => 404, 'unavailable' => 503, 'stale_200' => 200, default => 403 };

            return Http::response(['manifestHash' => str_repeat('f', 64)], $status);
        });
        try {
            $result = app(CloudflarePagesDirectDriver::class)->probe($batch);
            $this->assertSame($confirmed, $result?->confirmedDeployed ?? false);
            $this->assertSame($confirmed ? $hash : '', trim(file_get_contents($marker)));
            if ($confirmed) {
                $this->assertSame(0, $result->metadata['snapshot_health']['probeCount']);
                Http::assertSent(fn ($request) => $request->url() === $origin.'/404');
                Http::assertSent(fn ($request) => $request->url() === $origin.'/');
                Http::assertNotSent(fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '.html'));
            }
            Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
            Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://untrusted.example.test'));
        } finally {
            unlink($marker);
        }
    }

    public static function wafEvidenceCases(): array
    {
        return [
            'exact deployment with active domains' => ['healthy', true],
            'inactive custom domain' => ['inactive_domain', false],
            'older successful deployment' => ['old_deployment', false],
            'corrupt loader' => ['corrupt_loader', false],
            'corrupt canonical HTML' => ['corrupt_html', false],
            'canonical HTML redirect refused' => ['canonical_redirect', false],
            'forged manifest file map' => ['forged_file_map', false],
            'missing gate CSP' => ['missing_csp', false],
            'untrusted provider URL' => ['untrusted_url', false],
            '404 is not WAF evidence' => ['not_found', false],
            '503 is not WAF evidence' => ['unavailable', false],
            'stale 200 is not WAF evidence' => ['stale_200', false],
        ];
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
