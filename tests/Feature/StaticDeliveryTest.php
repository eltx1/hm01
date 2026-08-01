<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\ConfigVersionStatus;
use App\Enums\GamConnectionType;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\StaticDeliveryStatus;
use App\Models\AuditLog;
use App\Models\StaticDeliveryItem;
use App\Services\StaticDelivery\Contracts\StaticDeliveryDriverInterface;
use App\Services\StaticDelivery\CanonicalJson;
use App\Services\StaticDelivery\Data\StaticDeliverySnapshot;
use App\Services\StaticDelivery\Drivers\LocalFilesystemStaticDeliveryDriver;
use App\Services\StaticDelivery\Exceptions\StaticDeliveryException;
use App\Services\StaticDelivery\PublicPayloadGuard;
use App\Services\StaticDelivery\StaticDeliveryManager;
use App\Services\StaticDelivery\StaticDeliverySnapshotBuilder;
use App\Services\StaticDelivery\StaticPathGuard;
use App\Services\Inventory\SiteConfigPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
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
            'static-delivery.batch_delay_seconds' => 0,
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
        config(['static-delivery.batch_delay_seconds' => 900]);
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
        app(LocalFilesystemStaticDeliveryDriver::class)->deliver($snapshot, new \App\Models\StaticDeliveryBatch);
        $this->assertFileExists($this->dist.'/configs/test.json');

        $this->expectException(StaticDeliveryException::class);
        app(PublicPayloadGuard::class)->validate(['nested' => ['api_key' => 'should-never-be-public']]);
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
