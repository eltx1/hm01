<?php

namespace Tests\Feature;

use App\Models\StaticDeliveryBatch;
use App\Services\StaticDelivery\Data\StaticDeliverySnapshot;
use App\Services\StaticDelivery\Drivers\CloudflarePagesPipelineDriver;
use App\Services\StaticDelivery\Exceptions\StaticDeliveryException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ActiveStaticDeliveryAutomationTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
    private string $credential;
    private const COMMIT = '1111111111111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        $this->credential = tempnam(sys_get_temp_dir(), 'edge-test-');
        file_put_contents($this->credential, 'test-credential-not-a-real-token');
        config([
            'static-delivery.driver' => 'cloudflare-pages-pipeline',
            'static-delivery.cloudflare.github_token_reference' => 'file:'.$this->credential,
            'static-delivery.cloudflare.github_repository' => 'example/horus',
            'static-delivery.cloudflare.delivery_branch' => 'edge-delivery',
            'static-delivery.cloudflare.dry_run' => false,
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        @unlink($this->credential);
        parent::tearDown();
    }

    public function test_due_snapshot_dispatches_automatically_without_a_github_schedule_event(): void
    {
        $this->fakeRepository();
        $result = app(CloudflarePagesPipelineDriver::class)->deliver($this->snapshot(), $this->batch());

        $this->assertFalse($result->confirmedDeployed);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/dispatches')
            && $request['event_type'] === 'cloudflare-pages-static-delivery'
            && $request['client_payload']['delivery_commit'] === self::COMMIT
            && $request['client_payload']['batch_id'] === '01AUTOMATIONTESTBATCH');
    }

    public function test_retry_reuses_an_already_accepted_running_commit_without_duplicate_dispatch(): void
    {
        $this->fakeRepository([[
            'display_title' => 'Static edge '.self::COMMIT.' previous-batch',
            'status' => 'in_progress', 'conclusion' => null,
        ]]);
        app(CloudflarePagesPipelineDriver::class)->deliver($this->snapshot(), $this->batch());
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_failed_remote_run_can_be_dispatched_again(): void
    {
        $this->fakeRepository([[
            'display_title' => 'Static edge '.self::COMMIT.' previous-batch',
            'status' => 'completed', 'conclusion' => 'failure',
        ]]);
        app(CloudflarePagesPipelineDriver::class)->deliver($this->snapshot(), $this->batch());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/dispatches'));
    }

    public function test_dispatch_rejection_is_a_visible_failure_not_passive_success(): void
    {
        $this->fakeRepository([], 403);
        $this->expectException(StaticDeliveryException::class);
        $this->expectExceptionMessage('rejected the deployment workflow dispatch');
        app(CloudflarePagesPipelineDriver::class)->deliver($this->snapshot(), $this->batch());
    }

    public function test_dry_run_does_not_make_any_remote_request(): void
    {
        config(['static-delivery.cloudflare.dry_run' => true]);
        try {
            app(CloudflarePagesPipelineDriver::class)->deliver($this->snapshot(), $this->batch());
            $this->fail('Dry-run must stop before remote access.');
        } catch (StaticDeliveryException $exception) {
            $this->assertSame('DRY_RUN_ONLY', $exception->category);
        }
        Http::assertNothingSent();
    }

    public function test_green_workflow_with_stale_public_cdn_is_not_confirmed(): void
    {
        config(['static-delivery.external_sync.manifest_url' => 'https://cdn.example.test/delivery-manifest.json']);
        Http::fake([
            'https://api.github.com/repos/example/horus/actions/workflows/*' => Http::response(['workflow_runs' => [[
                'id' => 42, 'display_title' => 'Static edge '.self::COMMIT,
                'status' => 'completed', 'conclusion' => 'success',
            ]]]),
            'https://api.github.com/repos/example/horus/actions/runs/42/jobs' => Http::response(['jobs' => [[
                'steps' => [['name' => 'Deploy static project to Cloudflare Pages', 'conclusion' => 'success']],
            ]]]),
            'https://cdn.example.test/*' => Http::response(['manifestHash' => str_repeat('b', 64)]),
        ]);
        $this->assertNull(app(CloudflarePagesPipelineDriver::class)->probe($this->batch()));
    }

    public function test_automation_check_is_read_only_and_refuses_passive_or_dry_run_mode(): void
    {
        $this->artisan('static-delivery:automation-check')->assertSuccessful();
        config(['static-delivery.cloudflare.dry_run' => true]);
        $this->artisan('static-delivery:automation-check')->assertFailed();
        config(['static-delivery.driver' => 'external-pages-sync']);
        $this->artisan('static-delivery:automation-check')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_scheduler_readiness_checks_the_real_heartbeat_without_creating_one(): void
    {
        $this->artisan('static-delivery:automation-check --require-scheduler')->assertFailed();
        $this->assertDatabaseCount('system_heartbeats', 0);
        \App\Models\SystemHeartbeat::query()->create([
            'key' => 'scheduler', 'status' => 'HEALTHY', 'last_seen_at' => now()->subMinutes(10),
        ]);
        $this->artisan('static-delivery:automation-check --require-scheduler')->assertFailed();
        \App\Models\SystemHeartbeat::query()->where('key', 'scheduler')->update(['last_seen_at' => now()]);
        $this->artisan('static-delivery:automation-check --require-scheduler')->assertSuccessful();
        Http::assertNothingSent();
    }

    private function snapshot(): StaticDeliverySnapshot
    {
        return new StaticDeliverySnapshot(['configs/test.json' => '{}'], str_repeat('a', 64), 2, false);
    }

    private function batch(): StaticDeliveryBatch
    {
        $batch = new StaticDeliveryBatch([
            'manifest_hash' => str_repeat('a', 64),
            'provider_metadata' => ['delivery_commit' => self::COMMIT],
        ]);
        $batch->id = '01AUTOMATIONTESTBATCH';

        return $batch;
    }

    private function fakeRepository(array $runs = [], int $dispatchStatus = 204): void
    {
        Http::fake([
            'https://api.github.com/repos/example/horus/git/ref/heads/edge-delivery' => Http::response(['object' => ['sha' => self::COMMIT]]),
            'https://api.github.com/repos/example/horus/git/commits/*' => Http::response(['tree' => ['sha' => 'tree-sha']]),
            'https://api.github.com/repos/example/horus/git/trees/*' => Http::response(['tree' => [[
                'path' => 'configs/test.json', 'type' => 'blob', 'sha' => sha1("blob 2\0{}"),
            ]]]),
            'https://api.github.com/repos/example/horus/actions/workflows/*' => Http::response(['workflow_runs' => $runs]),
            'https://api.github.com/repos/example/horus/dispatches' => Http::response('', $dispatchStatus),
        ]);
    }
}
