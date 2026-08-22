<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\ConfigVersionStatus;
use App\Enums\GamConnectionType;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SiteStatus;
use App\Enums\StaticDeliveryPriority;
use App\Enums\StaticDeliveryStatus;
use App\Models\AuditLog;
use App\Models\SellerDeclaration;
use App\Models\StaticDeliveryBatch;
use App\Models\StaticDeliveryItem;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Operations\PlatformControlService;
use App\Services\StaticDelivery\CanonicalJson;
use App\Services\StaticDelivery\Contracts\StaticDeliveryDriverInterface;
use App\Services\StaticDelivery\Data\StaticDeliverySnapshot;
use App\Services\StaticDelivery\Drivers\LocalFilesystemStaticDeliveryDriver;
use App\Services\StaticDelivery\Exceptions\StaticDeliveryException;
use App\Services\StaticDelivery\PublicPayloadGuard;
use App\Services\StaticDelivery\StaticDeliveryManager;
use App\Services\StaticDelivery\StaticDeliveryOperationsService;
use App\Services\StaticDelivery\StaticDeliverySnapshotBuilder;
use App\Services\StaticDelivery\StaticDeliveryWindow;
use App\Services\StaticDelivery\StaticPathGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\Fakes\FakeStaticDeliveryDriver;
use Tests\TestCase;

class StaticDeliveryTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private FakeStaticDeliveryDriver $driver;

    private string $dist;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'static-delivery.normal_batch_interval_minutes' => 0,
            'static-delivery.retention_per_environment' => 2,
            'static-delivery.monthly_deployment_budget' => 20,
            'static-delivery.emergency_reserve' => 2,
            'static-delivery.file_budget.warning_threshold' => 100,
            'static-delivery.file_budget.hard_limit' => 200,
        ]);
        $this->driver = new FakeStaticDeliveryDriver;
        $this->app->instance(StaticDeliveryDriverInterface::class, $this->driver);
        $this->dist = storage_path('framework/testing/static-delivery-'.bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        File::deleteDirectory($this->dist);
        parent::tearDown();
    }

    public function test_publish_is_transactional_pending_and_remote_delivery_runs_after_commit(): void
    {
        [$site, $admin] = $this->siteWithPrimaryHorus();
        $baselineTransactionLevel = DB::transactionLevel();
        $version = app(SiteConfigPublisher::class)->publish($site, ConfigEnvironment::Production, $admin);

        $this->assertSame(ConfigVersionStatus::PendingDelivery, $version->status);
        $this->assertDatabaseHas('static_delivery_items', ['config_version_id' => $version->id, 'status' => 'PENDING']);
        $this->assertFalse(File::exists($this->dist));
        $batch = app(StaticDeliveryManager::class)->processPending();

        $this->assertSame(StaticDeliveryStatus::Deployed, $batch->status);
        $this->assertSame([$baselineTransactionLevel], $this->driver->transactionLevels);
        $this->assertCount(1, $this->driver->snapshots);
        $this->assertArrayHasKey('hm-loader.js', $this->driver->snapshots[0]->files);
        $this->assertArrayHasKey("configs/{$site->public_key}/manifest.json", $this->driver->snapshots[0]->files);
        $this->assertSame(ConfigVersionStatus::Deployed, $version->refresh()->status);
        $this->assertNotNull($version->published_at);
        $this->assertTrue(AuditLog::query()->where('event', 'site.config.delivery.queued')->exists());
    }

    public function test_batch_deduplicates_same_site_and_combines_multiple_sites(): void
    {
        [$first, $admin] = $this->siteWithPrimaryHorus();
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $second = $this->makeSiteFor($this->makePublisherFor($publisherUser), $publisherUser);
        $publisher = app(SiteConfigPublisher::class);
        $old = $publisher->publish($first, ConfigEnvironment::Production, $admin);
        $latest = $publisher->publish($first->refresh(), ConfigEnvironment::Production, $admin);
        $other = $publisher->publish($second, ConfigEnvironment::Production, $admin);

        $batch = app(StaticDeliveryManager::class)->processPending();

        $this->assertSame(2, $batch->item_count);
        $this->assertSame(ConfigVersionStatus::Superseded, $old->refresh()->status);
        $this->assertSame(ConfigVersionStatus::Deployed, $latest->refresh()->status);
        $this->assertSame(ConfigVersionStatus::Deployed, $other->refresh()->status);
        $this->assertSame(1, StaticDeliveryItem::withoutGlobalScopes()->where('status', StaticDeliveryStatus::Superseded->value)->count());
        $this->assertCount(1, $this->driver->snapshots);
    }

    public function test_failed_upload_is_retryable_and_keeps_database_payload(): void
    {
        [$site, $admin] = $this->siteWithPrimaryHorus();
        $version = app(SiteConfigPublisher::class)->publish($site, ConfigEnvironment::Production, $admin);
        $this->driver->fail = true;
        $batch = app(StaticDeliveryManager::class)->processPending();

        $this->assertSame(StaticDeliveryStatus::RetryScheduled, $batch->status);
        $this->assertSame('FAKE_UPLOAD_FAILED', $batch->error_code);
        $this->assertSame(ConfigVersionStatus::DeliveryFailed, $version->refresh()->status);
        $this->assertNotEmpty($version->payload);

        $this->driver->fail = false;
        $batch->items()->update(['available_at' => now()]);
        $retried = app(StaticDeliveryManager::class)->processPending();
        $this->assertSame(StaticDeliveryStatus::Deployed, $retried->status);
        $this->assertSame(ConfigVersionStatus::Deployed, $version->refresh()->status);
    }

    public function test_emergency_pause_is_urgent_and_ready_without_batch_delay(): void
    {
        config(['static-delivery.normal_batch_interval_minutes' => 30]);
        [$site, $admin] = $this->siteWithPrimaryHorus();
        $version = app(SiteConfigPublisher::class)->pauseImmediately($site, $admin);
        $item = $version->deliveryItem;

        $this->assertSame('paused', $version->payload['status']);
        $this->assertSame('URGENT', $item->priority->value);
        $this->assertTrue($item->available_at->lessThanOrEqualTo(now()));
        $this->assertSame(StaticDeliveryStatus::Deployed, app(StaticDeliveryManager::class)->processPending()->status);
    }

    public function test_local_driver_writes_snapshot_and_guards_paths_and_secret_keys(): void
    {
        config(['static-delivery.local_root' => $this->dist]);
        $snapshot = new StaticDeliverySnapshot(['configs/test.json' => "{}\n"], str_repeat('a', 64), 3, false);
        app(LocalFilesystemStaticDeliveryDriver::class)->deliver($snapshot, new StaticDeliveryBatch);
        $this->assertFileExists($this->dist.'/configs/test.json');

        $this->expectException(StaticDeliveryException::class);
        app(PublicPayloadGuard::class)->validate(['nested' => ['api_key' => 'should-never-be-public']]);
    }

    public function test_local_driver_removes_stale_supply_chain_and_runtime_artifacts(): void
    {
        config(['static-delivery.local_root' => $this->dist]);
        File::ensureDirectoryExists($this->dist.'/supply/domains/removed.example');
        File::ensureDirectoryExists($this->dist.'/traffic-gate');
        File::put($this->dist.'/supply/domains/removed.example/ads.txt', "stale\n");
        File::put($this->dist.'/traffic-gate/obsolete.html', "stale\n");
        File::put($this->dist.'/operator-note.txt', "preserve\n");

        $snapshot = new StaticDeliverySnapshot([
            'sellers.json' => "{\"version\":\"1.0\",\"sellers\":[]}\n",
        ], str_repeat('b', 64), 35, false);

        app(LocalFilesystemStaticDeliveryDriver::class)->deliver($snapshot, new StaticDeliveryBatch);

        $this->assertFileDoesNotExist($this->dist.'/supply/domains/removed.example/ads.txt');
        $this->assertFileDoesNotExist($this->dist.'/traffic-gate/obsolete.html');
        $this->assertFileExists($this->dist.'/operator-note.txt');
        $this->assertFileExists($this->dist.'/sellers.json');
    }

    public function test_snapshot_retention_hashing_and_file_budget_are_enforced(): void
    {
        [$site, $admin] = $this->siteWithPrimaryHorus();
        $publisher = app(SiteConfigPublisher::class);
        $publisher->publish($site, ConfigEnvironment::Production, $admin);
        $publisher->publish($site->refresh(), ConfigEnvironment::Production, $admin);
        $publisher->publish($site->refresh(), ConfigEnvironment::Production, $admin);
        $snapshot = app(StaticDeliverySnapshotBuilder::class)->build();
        $versioned = collect(array_keys($snapshot->files))->filter(fn ($path) => str_contains($path, "configs/{$site->public_key}/production.v"));
        $this->assertCount(2, $versioned);
        $this->assertSame($snapshot->manifestHash, app(StaticDeliverySnapshotBuilder::class)->build()->manifestHash);

        config(['static-delivery.file_budget.hard_limit' => 1]);
        $this->expectException(StaticDeliveryException::class);
        app(StaticDeliverySnapshotBuilder::class)->build();
    }

    public function test_global_control_artifact_is_complete_backward_compatible_and_deterministic(): void
    {
        [, $admin] = $this->siteWithPrimaryHorus();
        $controls = app(PlatformControlService::class);
        $controls->set('PLATFORM', null, 'GAM', true, 'Task 23 global GAM stop.', $admin);
        $controls->set('PLATFORM', null, 'PREBID', false, 'Task 23 global Prebid enabled.', $admin);
        $controls->set('PLATFORM', null, 'DIRECT_JS', true, 'Task 23 global Direct JS stop.', $admin);
        $controls->set('PLATFORM', null, 'NATIVE_DEMAND', false, 'Task 23 legacy native compatibility.', $admin);

        $first = app(StaticDeliverySnapshotBuilder::class)->build();
        $second = app(StaticDeliverySnapshotBuilder::class)->build();
        $artifact = json_decode($first->files['configs/_global/control.json'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(2, $artifact['schemaVersion']);
        $this->assertSame([
            'adServingDisabled' => false,
            'directJsDisabled' => true,
            'gamDisabled' => true,
            'nativeDemandDisabled' => false,
            'prebidDisabled' => false,
        ], $artifact['controls']);
        $this->assertSame($first->files['configs/_global/control.json'], $second->files['configs/_global/control.json']);
        $this->assertSame($first->manifestHash, $second->manifestHash);

        $controls->set('PLATFORM', null, 'DIRECT_JS', false, 'Task 23 global Direct JS enabled.', $admin);
        $controls->set('PLATFORM', null, 'NATIVE_DEMAND', true, 'Task 23 legacy native stop.', $admin);
        $legacyArtifact = json_decode(
            app(StaticDeliverySnapshotBuilder::class)->build()->files['configs/_global/control.json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertTrue($legacyArtifact['controls']['directJsDisabled']);
        $this->assertTrue($legacyArtifact['controls']['nativeDemandDisabled']);
    }

    public function test_platform_engine_kills_bypass_any_normal_batching_window(): void
    {
        config(['static-delivery.normal_batch_interval_minutes' => 30]);
        [$site, $admin] = $this->siteWithPrimaryHorus();
        $site->update(['status' => SiteStatus::Active]);
        $site->siteConfig()->update(['status' => 'ACTIVE', 'immediate_pause' => false]);
        $session = ['two_factor_passed_at' => now()->timestamp];

        foreach ([
            'GAM' => 'DISABLE PLATFORM GAM',
            'PREBID' => 'DISABLE PLATFORM PREBID',
            'DIRECT_JS' => 'DISABLE PLATFORM DIRECT JS',
        ] as $control => $confirmation) {
            $this->actingAs($admin)->withSession($session)->post(route('admin.operations.controls'), [
                'scope_type' => 'PLATFORM',
                'control_key' => $control,
                'is_disabled' => '1',
                'reason' => "Task 23 urgent {$control} safety stop.",
                'current_password' => 'password',
                'impact_confirmation' => $confirmation,
            ])->assertSessionHasNoErrors();

            $item = StaticDeliveryItem::withoutGlobalScopes()
                ->where('site_id', $site->id)
                ->latest('created_at')
                ->firstOrFail();
            $this->assertSame(StaticDeliveryPriority::Urgent, $item->priority);
            $this->assertTrue($item->available_at->lessThanOrEqualTo(now()));
        }
    }

    public function test_site_key_path_traversal_is_rejected(): void
    {
        $this->expectException(StaticDeliveryException::class);
        app(StaticPathGuard::class)->siteKey('../escape');
    }

    public function test_canonical_json_is_stable_across_database_key_ordering(): void
    {
        $encoder = app(CanonicalJson::class);

        $this->assertSame(
            $encoder->encode(['z' => ['b' => 2, 'a' => 1], 'a' => [['y' => 2, 'x' => 1]]]),
            $encoder->encode(['a' => [['x' => 1, 'y' => 2]], 'z' => ['a' => 1, 'b' => 2]]),
        );
        $this->assertNotSame($encoder->encode(['items' => [1, 2]]), $encoder->encode(['items' => [2, 1]]));
    }

    public function test_snapshot_contains_ads_txt_1_1_sellers_json_and_synthetic_health(): void
    {
        [$site] = $this->siteWithPrimaryHorus();
        $site->publisher()->update(['business_domain' => 'publisher.example']);
        SellerDeclaration::withoutGlobalScopes()->create([
            'organization_id' => $site->organization_id,
            'publisher_id' => $site->publisher_id,
            'site_id' => null,
            'seller_id' => 'publisher-42',
            'seller_type' => 'PUBLISHER',
            'ads_txt_relationship' => 'DIRECT',
            'name' => 'Publisher Example',
            'domain' => 'publisher.example',
            'status' => 'ACTIVE',
        ]);

        $files = app(StaticDeliverySnapshotBuilder::class)->build()->files;
        $adsTxt = $files['supply/sites/'.$site->public_key.'/ads.txt'];
        $sellers = json_decode($files['sellers.json'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('OWNERDOMAIN=publisher.example', $adsTxt);
        $this->assertStringNotContainsString('MANAGERDOMAIN=', $adsTxt);
        $this->assertSame('publisher-42', $sellers['sellers'][0]['seller_id']);
        $this->assertArrayNotHasKey('identifiers', $sellers);
        $this->assertSame($files['sellers.json'], $files['supply/sellers.json']);
        $this->assertStringContainsString("/sellers.json\n", $files['_headers']);
        $this->assertStringContainsString('Content-Type: application/json; charset=utf-8', $files['_headers']);
        $this->assertArrayHasKey('health/delivery.json', $files);
    }

    public function test_normal_batch_boundary_is_deterministic_in_utc(): void
    {
        config(['static-delivery.normal_batch_interval_minutes' => 30]);
        $window = app(StaticDeliveryWindow::class);

        foreach (['10:01:00', '10:10:00', '10:29:59'] as $time) {
            $this->assertSame(
                '2026-08-14 10:30:00',
                $window->nextNormalBoundary(CarbonImmutable::parse("2026-08-14 {$time}", 'UTC'))->format('Y-m-d H:i:s'),
            );
        }
        $this->assertSame('2026-08-14 11:00:00', $window->nextNormalBoundary(CarbonImmutable::parse('2026-08-14 10:30:00', 'UTC'))->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-14 11:00:00', $window->nextNormalBoundary(CarbonImmutable::parse('2026-08-14 10:31:00', 'UTC'))->format('Y-m-d H:i:s'));
    }

    public function test_same_window_changes_coalesce_and_only_newest_site_version_is_selected(): void
    {
        config(['static-delivery.normal_batch_interval_minutes' => 30]);
        CarbonImmutable::setTestNow('2026-08-14 10:01:00 UTC');
        [$first, $admin] = $this->siteWithPrimaryHorus();
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $second = $this->makeSiteFor($this->makePublisherFor($publisherUser), $publisherUser);
        $publisher = app(SiteConfigPublisher::class);
        $old = $publisher->publish($first, ConfigEnvironment::Production, $admin);
        CarbonImmutable::setTestNow('2026-08-14 10:10:00 UTC');
        $latest = $publisher->publish($first->refresh(), ConfigEnvironment::Production, $admin);
        CarbonImmutable::setTestNow('2026-08-14 10:29:00 UTC');
        $other = $publisher->publish($second, ConfigEnvironment::Production, $admin);

        $this->assertSame(1, StaticDeliveryItem::withoutGlobalScopes()->get()->pluck('available_at')->map->format('H:i:s')->unique()->count());
        $this->assertSame('10:30:00', $other->deliveryItem->available_at->format('H:i:s'));
        $this->assertNull(app(StaticDeliveryManager::class)->processPending());

        CarbonImmutable::setTestNow('2026-08-14 10:30:00 UTC');
        $batch = app(StaticDeliveryManager::class)->processPending();
        $this->assertSame(2, $batch->item_count);
        $this->assertSame(ConfigVersionStatus::Superseded, $old->refresh()->status);
        $this->assertSame(ConfigVersionStatus::Deployed, $latest->refresh()->status);
        $this->assertSame(ConfigVersionStatus::Deployed, $other->refresh()->status);
        $this->assertCount(1, $this->driver->snapshots);
        CarbonImmutable::setTestNow();
    }

    public function test_change_after_half_hour_waits_for_the_next_boundary(): void
    {
        config(['static-delivery.normal_batch_interval_minutes' => 30]);
        CarbonImmutable::setTestNow('2026-08-14 10:31:00 UTC');
        [$site, $admin] = $this->siteWithPrimaryHorus();
        $version = app(SiteConfigPublisher::class)->publish($site, ConfigEnvironment::Production, $admin);
        $this->assertSame('11:00:00', $version->deliveryItem->available_at->format('H:i:s'));

        CarbonImmutable::setTestNow('2026-08-14 10:59:59 UTC');
        $this->assertNull(app(StaticDeliveryManager::class)->processPending());
        CarbonImmutable::setTestNow('2026-08-14 11:00:00 UTC');
        $this->assertSame(StaticDeliveryStatus::Deployed, app(StaticDeliveryManager::class)->processPending()->status);
        CarbonImmutable::setTestNow();
    }

    public function test_delayed_scheduler_does_not_publish_a_later_normal_window_early(): void
    {
        config(['static-delivery.normal_batch_interval_minutes' => 30]);
        CarbonImmutable::setTestNow('2026-08-14 10:01:00 UTC');
        [$site, $admin] = $this->siteWithPrimaryHorus();
        $publisher = app(SiteConfigPublisher::class);
        $first = $publisher->publish($site, ConfigEnvironment::Production, $admin);
        CarbonImmutable::setTestNow('2026-08-14 10:31:00 UTC');
        $later = $publisher->publish($site->refresh(), ConfigEnvironment::Production, $admin);

        CarbonImmutable::setTestNow('2026-08-14 10:32:00 UTC');
        $firstBatch = app(StaticDeliveryManager::class)->processPending();
        $current = json_decode(
            $this->driver->snapshots[0]->files["configs/{$site->public_key}/production.json"],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame(1, $current['configVersion']);
        $this->assertSame(ConfigVersionStatus::Deployed, $first->refresh()->status);
        $this->assertSame(ConfigVersionStatus::PendingDelivery, $later->refresh()->status);
        $this->assertSame(StaticDeliveryStatus::Pending, $later->deliveryItem->refresh()->status);
        $this->assertSame(1, $firstBatch->item_count);

        CarbonImmutable::setTestNow('2026-08-14 11:00:00 UTC');
        $secondBatch = app(StaticDeliveryManager::class)->processPending();
        $this->assertSame(ConfigVersionStatus::Deployed, $later->refresh()->status);
        $this->assertSame(StaticDeliveryStatus::Deployed, $secondBatch->status);
    }

    public function test_urgent_newer_version_supersedes_an_older_future_normal_item(): void
    {
        config(['static-delivery.normal_batch_interval_minutes' => 30]);
        CarbonImmutable::setTestNow('2026-08-14 10:01:00 UTC');
        [$site, $admin] = $this->siteWithPrimaryHorus();
        $normal = app(SiteConfigPublisher::class)->publish($site, ConfigEnvironment::Production, $admin);
        CarbonImmutable::setTestNow('2026-08-14 10:02:00 UTC');
        $urgent = app(SiteConfigPublisher::class)->pauseImmediately($site->refresh(), $admin);

        $batch = app(StaticDeliveryManager::class)->processPending();
        $this->assertSame(StaticDeliveryPriority::Urgent, $batch->priority);
        $this->assertSame(ConfigVersionStatus::Superseded, $normal->refresh()->status);
        $this->assertSame(StaticDeliveryStatus::Superseded, $normal->deliveryItem->refresh()->status);
        $this->assertSame(ConfigVersionStatus::Deployed, $urgent->refresh()->status);
        CarbonImmutable::setTestNow('2026-08-14 10:30:00 UTC');
        $this->assertNull(app(StaticDeliveryManager::class)->processPending());
        $this->assertCount(1, $this->driver->snapshots);
    }

    public function test_no_pending_work_creates_no_batch_remote_submission_or_budget_use(): void
    {
        $this->assertNull(app(StaticDeliveryManager::class)->processPending());
        $this->assertDatabaseCount('static_delivery_batches', 0);
        $this->assertSame([], $this->driver->snapshots);
        $this->assertSame(0, app(StaticDeliveryOperationsService::class)->snapshot()['budget']['used']);
    }

    public function test_identical_manifest_records_deduplication_without_remote_or_budget_use(): void
    {
        [$site, $admin] = $this->siteWithPrimaryHorus();
        app(SiteConfigPublisher::class)->publish($site, ConfigEnvironment::Production, $admin);
        $manifest = app(StaticDeliverySnapshotBuilder::class)->build()->manifestHash;
        $original = StaticDeliveryBatch::create([
            'status' => StaticDeliveryStatus::Deployed,
            'priority' => StaticDeliveryPriority::Normal,
            'driver' => 'fake-cloudflare',
            'manifest_hash' => $manifest,
            'remote_deployment_id' => 'confirmed-original',
            'deployed_at' => now()->subMinute(),
        ]);

        $batch = app(StaticDeliveryManager::class)->processPending();

        $this->assertTrue($batch->is_deduplicated);
        $this->assertNull($batch->submitted_at);
        $this->assertSame($original->id, $batch->provider_metadata['deduplicated_from_batch']);
        $this->assertSame([], $this->driver->snapshots);
        $this->assertSame(0, app(StaticDeliveryOperationsService::class)->snapshot()['budget']['used']);
    }

    public function test_deploy_now_accelerates_pending_normal_work_through_the_manager(): void
    {
        config(['static-delivery.normal_batch_interval_minutes' => 30]);
        CarbonImmutable::setTestNow('2026-08-14 10:01:00 UTC');
        [$site, $admin] = $this->siteWithPrimaryHorus();
        $version = app(SiteConfigPublisher::class)->publish($site, ConfigEnvironment::Production, $admin);
        $this->assertTrue($version->deliveryItem->available_at->isFuture());

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.operations.static-delivery.deploy-now'), $this->deployNowPayload('Publish approved launch changes now.'))
            ->assertRedirect()
            ->assertSessionHas('status');

        $batch = StaticDeliveryBatch::firstOrFail();
        $this->assertSame(StaticDeliveryStatus::Deployed, $batch->status);
        $this->assertSame(StaticDeliveryPriority::Normal, $batch->priority);
        $this->assertSame('MANUAL', $batch->trigger);
        $this->assertSame($admin->id, $batch->created_by);
        $this->assertSame(ConfigVersionStatus::Deployed, $version->refresh()->status);
        $this->assertCount(1, $this->driver->snapshots);
        CarbonImmutable::setTestNow();
    }

    public function test_deploy_now_with_nothing_pending_is_clean_audited_no_op(): void
    {
        [, $admin] = $this->siteWithPrimaryHorus();
        $reason = 'Verify that the pending queue is empty.';

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.operations.static-delivery.deploy-now'), $this->deployNowPayload($reason))
            ->assertSessionHas('status', 'No pending static changes to deploy.');

        $this->assertDatabaseCount('static_delivery_batches', 0);
        $this->assertSame([], $this->driver->snapshots);
        $audit = AuditLog::query()->where('event', 'static.delivery.deploy_now.requested')->firstOrFail();
        $this->assertSame('NO_PENDING', $audit->new_values['outcome']);
        $this->assertSame($reason, $audit->new_values['reason']);
    }

    public function test_deploy_now_cannot_bypass_normal_budget_but_urgent_can_use_reserve(): void
    {
        config([
            'static-delivery.monthly_deployment_budget' => 2,
            'static-delivery.emergency_reserve' => 1,
        ]);
        [$site, $admin] = $this->siteWithPrimaryHorus();
        StaticDeliveryBatch::create([
            'status' => StaticDeliveryStatus::Deployed,
            'priority' => StaticDeliveryPriority::Normal,
            'driver' => 'fake-cloudflare',
            'submitted_at' => now()->subMinute(),
            'deployed_at' => now()->subMinute(),
            'remote_deployment_id' => 'normal-budget-used',
        ]);
        app(SiteConfigPublisher::class)->publish($site, ConfigEnvironment::Production, $admin);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.operations.static-delivery.deploy-now'), $this->deployNowPayload('Accelerate normal work without reserve bypass.'))
            ->assertSessionHas('error');
        $blocked = StaticDeliveryBatch::query()->whereNotNull('error_code')->firstOrFail();
        $this->assertSame('DEPLOYMENT_BUDGET_EXHAUSTED', $blocked->error_code);
        $this->assertSame(StaticDeliveryStatus::RetryScheduled, $blocked->status);
        $this->assertSame([], $this->driver->snapshots);

        $blocked->items()->update(['status' => StaticDeliveryStatus::Superseded->value]);
        $urgentVersion = app(SiteConfigPublisher::class)->publishUrgent($site->refresh(), ConfigEnvironment::Production, $admin);
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.operations.static-delivery.deploy-now'), $this->deployNowPayload('Process the existing genuine urgent safety item.'))
            ->assertSessionHas('status');
        $urgentBatch = StaticDeliveryBatch::query()->where('priority', StaticDeliveryPriority::Urgent->value)->firstOrFail();
        $this->assertSame(StaticDeliveryPriority::Urgent, $urgentBatch->priority);
        $this->assertSame(StaticDeliveryStatus::Deployed, $urgentBatch->status);
        $this->assertSame(ConfigVersionStatus::Deployed, $urgentVersion->refresh()->status);
        $this->assertCount(1, $this->driver->snapshots);
    }

    public function test_double_click_and_process_lock_cannot_duplicate_remote_deployment(): void
    {
        [$site, $admin] = $this->siteWithPrimaryHorus();
        app(SiteConfigPublisher::class)->publish($site, ConfigEnvironment::Production, $admin);
        $session = ['two_factor_passed_at' => now()->timestamp];
        $payload = $this->deployNowPayload('Operator intentionally publishes the pending change.');

        $held = Cache::lock(StaticDeliveryManager::PROCESS_LOCK, 60);
        $this->assertTrue($held->get());
        $this->actingAs($admin)->withSession($session)->post(route('admin.operations.static-delivery.deploy-now'), $payload)
            ->assertSessionHas('error');
        $this->assertNull(app(StaticDeliveryManager::class)->processPending());
        $this->assertDatabaseCount('static_delivery_batches', 0);
        $held->release();

        $this->actingAs($admin)->withSession($session)->post(route('admin.operations.static-delivery.deploy-now'), $payload)
            ->assertSessionHas('status');
        $this->actingAs($admin)->withSession($session)->post(route('admin.operations.static-delivery.deploy-now'), $payload)
            ->assertSessionHas('status', 'No pending static changes to deploy.');
        $this->assertDatabaseCount('static_delivery_batches', 1);
        $this->assertCount(1, $this->driver->snapshots);
        $this->assertSame(3, AuditLog::query()->where('event', 'static.delivery.deploy_now.requested')->count());
    }

    public function test_deploy_now_preserves_secret_and_checksum_guards(): void
    {
        [$site, $admin] = $this->siteWithPrimaryHorus();
        $secretVersion = app(SiteConfigPublisher::class)->publish($site, ConfigEnvironment::Production, $admin);
        $secretVersion->update(['payload' => array_merge($secretVersion->payload, ['api_key' => 'must-never-publish'])]);
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.operations.static-delivery.deploy-now'), $this->deployNowPayload('Validate public payload guard during manual deploy.'));
        $this->assertSame('SECRET_KEY_REJECTED', StaticDeliveryBatch::query()->latest()->value('error_code'));
        $this->assertSame([], $this->driver->snapshots);

        StaticDeliveryItem::withoutGlobalScopes()->where('config_version_id', $secretVersion->id)->update(['status' => StaticDeliveryStatus::Superseded->value]);
        config(['static-delivery.retention_per_environment' => 1]);
        $checksumVersion = app(SiteConfigPublisher::class)->publish($site->refresh(), ConfigEnvironment::Production, $admin);
        $checksumVersion->update(['payload' => array_merge($checksumVersion->payload, ['status' => 'tampered'])]);
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.operations.static-delivery.deploy-now'), $this->deployNowPayload('Validate checksum guard during manual deploy.'));
        $this->assertTrue(StaticDeliveryBatch::query()->where('error_code', 'CHECKSUM_MISMATCH')->exists());
        $this->assertSame([], $this->driver->snapshots);
    }

    public function test_static_delivery_ui_and_deploy_now_are_internal_and_permission_guarded(): void
    {
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia);
        $operationsAdmin = $this->makeUser($horus, RoleName::OperationsAdmin);
        $viewOnly = $this->makeUser($horus, RoleName::AdOpsAdmin);
        $publisher = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $session = ['two_factor_passed_at' => now()->timestamp];

        $this->actingAs($operationsAdmin)->withSession($session)->get(route('admin.operations.index'))
            ->assertOk()
            ->assertSee('Static Delivery')
            ->assertSee('Deploy Pending Changes Now')
            ->assertSee('Monthly deployment budget')
            ->assertSee('Current snapshot evidence');
        $this->actingAs($viewOnly)->withSession($session)->get(route('admin.operations.index'))
            ->assertOk()
            ->assertDontSee('I confirm this will process currently pending NORMAL static changes');
        $this->actingAs($viewOnly)->withSession($session)->post(route('admin.operations.static-delivery.deploy-now'), $this->deployNowPayload('View-only user cannot publish changes.'))
            ->assertForbidden();
        $this->actingAs($publisher)->withSession($session)->post(route('admin.operations.static-delivery.deploy-now'), $this->deployNowPayload('Publisher cannot publish static edge changes.'))
            ->assertForbidden();
    }

    public function test_manual_deploy_requires_confirmation_password_reason_and_audits_actor(): void
    {
        [$site, $admin] = $this->siteWithPrimaryHorus();
        app(SiteConfigPublisher::class)->publish($site, ConfigEnvironment::Production, $admin);
        $session = ['two_factor_passed_at' => now()->timestamp];
        $route = route('admin.operations.static-delivery.deploy-now');

        $this->actingAs($admin)->withSession($session)->post($route, [])->assertSessionHasErrors(['reason', 'current_password', 'confirm_deploy']);
        $this->actingAs($admin)->withSession($session)->post($route, $this->deployNowPayload('Audited deployment reason.', ['confirm_deploy' => null]))->assertSessionHasErrors('confirm_deploy');
        $this->actingAs($admin)->withSession($session)->post($route, $this->deployNowPayload('Audited deployment reason.', ['current_password' => 'wrong']))->assertSessionHasErrors('current_password');
        $this->actingAs($admin)->withSession($session)->post($route, $this->deployNowPayload('Audited deployment reason.'))->assertSessionHasNoErrors();

        $audit = AuditLog::query()->where('event', 'static.delivery.deploy_now.requested')->firstOrFail();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('Audited deployment reason.', $audit->new_values['reason']);
        $this->assertSame('PROCESSED', $audit->new_values['outcome']);
    }

    public function test_operations_snapshot_surfaces_persisted_budget_file_retry_and_overdue_warnings(): void
    {
        config([
            'static-delivery.normal_batch_interval_minutes' => 30,
            'static-delivery.monthly_deployment_budget' => 4,
            'static-delivery.emergency_reserve' => 1,
            'static-delivery.budget_warning_threshold' => 2,
            'static-delivery.file_budget.warning_threshold' => 10,
            'static-delivery.file_budget.hard_limit' => 20,
            'static-delivery.pending_stale_grace_minutes' => 5,
        ]);
        CarbonImmutable::setTestNow('2026-08-14 10:01:00 UTC');
        [$site, $admin] = $this->siteWithPrimaryHorus();
        app(SiteConfigPublisher::class)->publish($site, ConfigEnvironment::Production, $admin);
        foreach (range(1, 4) as $index) {
            StaticDeliveryBatch::create([
                'status' => StaticDeliveryStatus::Deployed,
                'priority' => $index > 3 ? StaticDeliveryPriority::Urgent : StaticDeliveryPriority::Normal,
                'driver' => 'fake-cloudflare',
                'manifest_hash' => str_repeat((string) $index, 64),
                'file_count' => $index === 4 ? 10 : 1,
                'total_bytes' => $index === 4 ? 4096 : 128,
                'submitted_at' => now()->addSeconds($index),
                'deployed_at' => now()->addSeconds($index),
                'remote_deployment_id' => 'remote-'.$index,
            ]);
        }
        StaticDeliveryBatch::create([
            'status' => StaticDeliveryStatus::RetryScheduled,
            'priority' => StaticDeliveryPriority::Normal,
            'driver' => 'fake-cloudflare',
            'error_code' => 'FAKE_RETRY',
            'next_retry_at' => now()->addMinutes(5),
        ]);
        CarbonImmutable::setTestNow('2026-08-14 10:36:00 UTC');

        $snapshot = app(StaticDeliveryOperationsService::class)->snapshot();
        $codes = collect($snapshot['warnings'])->pluck('code')->all();
        $this->assertSame(4, $snapshot['budget']['used']);
        $this->assertSame(0, $snapshot['budget']['normal_remaining']);
        $this->assertSame(1, $snapshot['budget']['emergency_consumed']);
        $this->assertSame(10, $snapshot['snapshot']['file_count']);
        $this->assertContains('NORMAL_BUDGET_EXHAUSTED', $codes);
        $this->assertContains('EMERGENCY_RESERVE_CONSUMED', $codes);
        $this->assertContains('STATIC_FILE_BUDGET_APPROACHING', $codes);
        $this->assertContains('STATIC_DELIVERY_FAILURE', $codes);
        $this->assertContains('STATIC_DELIVERY_OVERDUE', $codes);
        CarbonImmutable::setTestNow();
    }

    /** @param array<string, mixed> $overrides */
    private function deployNowPayload(string $reason, array $overrides = []): array
    {
        return array_merge([
            'reason' => $reason,
            'current_password' => 'password',
            'confirm_deploy' => '1',
        ], $overrides);
    }

    private function siteWithPrimaryHorus(): array
    {
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia);
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $connection = $this->makeGamConnection($horus, $admin, [
            'type' => GamConnectionType::HorusGam,
            'network_code' => '123456789',
            'is_primary' => true,
            'configuration' => ['root_ad_unit_id' => '1111'],
        ]);
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($publisherUser), $publisherUser, ['primary_domain' => 'publisher.example']);

        return [$site, $admin, $connection];
    }
}
