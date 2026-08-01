<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SiteStatus;
use App\Enums\StaticDeliveryPriority;
use App\Models\ConfigVersion;
use App\Models\Site;
use App\Models\User;
use App\Services\Inventory\InventoryManager;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Sites\SiteLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class AutomaticRuntimePublishingTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_draft_and_approval_do_not_publish_but_first_activation_queues_live_production(): void
    {
        [$site, $publisherUser, $admin] = $this->siteAndActors();
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site, [
            'name' => 'Top', 'code' => 'top', 'sizes' => [['width' => 728, 'height' => 90]],
        ], $admin);
        $inventory->createPlacement($site, [
            'name' => 'Top', 'code' => 'top', 'type' => 'DISPLAY', 'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id, 'sizes' => [['width' => 728, 'height' => 90]],
        ], $admin);

        $this->assertDatabaseCount('config_versions', 0);
        $lifecycle = app(SiteLifecycleService::class);
        $lifecycle->transition($site, SiteStatus::PendingReview, $publisherUser, 'Ready for review.');
        $lifecycle->transition($site->refresh(), SiteStatus::Approved, $admin, 'Approved.');
        $this->assertDatabaseCount('config_versions', 0);

        $lifecycle->transition($site->refresh(), SiteStatus::Active, $admin, 'Activate.');

        $version = ConfigVersion::withoutGlobalScopes()->sole();
        $this->assertSame(ConfigEnvironment::Production, $version->environment);
        $this->assertSame(1, $version->version);
        $this->assertSame('active', $version->payload['status']);
        $this->assertSame('top', $version->payload['placements'][0]['code']);
        $this->assertDatabaseHas('static_delivery_items', [
            'config_version_id' => $version->id,
            'priority' => StaticDeliveryPriority::Normal->value,
            'status' => 'PENDING',
        ]);
    }

    public function test_active_runtime_changes_publish_automatically_and_bulk_changes_publish_once(): void
    {
        [$site, $publisherUser, $admin] = $this->siteAndActors();
        $this->activate($site, $publisherUser, $admin);
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site->refresh(), [
            'name' => 'Article', 'code' => 'article', 'sizes' => [['width' => 300, 'height' => 250]],
        ], $admin);

        $inventory->bulkCreatePlacements($site->refresh(), [
            [
                'name' => 'Article Top', 'code' => 'article_top', 'type' => 'DISPLAY', 'status' => 'ACTIVE',
                'ad_unit_id' => $adUnit->id, 'sizes' => [['width' => 300, 'height' => 250]],
            ],
            [
                'name' => 'Article Bottom', 'code' => 'article_bottom', 'type' => 'DISPLAY', 'status' => 'ACTIVE',
                'ad_unit_id' => $adUnit->id, 'sizes' => [['width' => 300, 'height' => 250]],
            ],
        ], $admin);

        $this->assertSame(2, ConfigVersion::withoutGlobalScopes()->where('site_id', $site->id)->count());
        $latest = ConfigVersion::withoutGlobalScopes()->where('site_id', $site->id)->latest('version')->firstOrFail();
        $this->assertCount(2, $latest->payload['placements']);

        $inventory->setPageTargeting($site->refresh(), ['section' => ['news']], $admin);
        $latest = ConfigVersion::withoutGlobalScopes()->where('site_id', $site->id)->latest('version')->firstOrFail();
        $this->assertSame(3, $latest->version);
        $this->assertSame(['news'], $latest->payload['pageTargeting']['section']);
    }

    public function test_suspension_and_emergency_pause_each_queue_one_urgent_paused_version(): void
    {
        [$site, $publisherUser, $admin] = $this->siteAndActors();
        $this->activate($site, $publisherUser, $admin);
        $lifecycle = app(SiteLifecycleService::class);

        $lifecycle->transition($site->refresh(), SiteStatus::Suspended, $admin, 'Operational suspension.');
        $suspended = ConfigVersion::withoutGlobalScopes()->where('site_id', $site->id)->latest('version')->firstOrFail();
        $this->assertSame(2, $suspended->version);
        $this->assertSame('paused', $suspended->payload['status']);
        $this->assertSame(StaticDeliveryPriority::Urgent, $suspended->deliveryItem->priority);

        [$other, $otherPublisherUser] = $this->additionalSiteAndPublisher();
        $this->activate($other, $otherPublisherUser, $admin);
        $before = ConfigVersion::withoutGlobalScopes()->where('site_id', $other->id)->count();
        $lifecycle->emergencyPause($other->refresh(), $admin, 'Immediate safety stop.');

        $this->assertSame($before + 1, ConfigVersion::withoutGlobalScopes()->where('site_id', $other->id)->count());
        $paused = ConfigVersion::withoutGlobalScopes()->where('site_id', $other->id)->latest('version')->firstOrFail();
        $this->assertSame('paused', $paused->payload['status']);
        $this->assertTrue($paused->payload['immediatePause']);
        $this->assertSame(StaticDeliveryPriority::Urgent, $paused->deliveryItem->priority);
    }

    public function test_inactive_runtime_mutations_do_not_queue_and_manual_production_is_forced_paused(): void
    {
        [$site, , $admin] = $this->siteAndActors();
        $publisher = app(SiteConfigPublisher::class);

        $this->assertNull($publisher->publishActiveProduction($site, $admin));
        $this->assertDatabaseCount('config_versions', 0);

        $manual = $publisher->publish($site, ConfigEnvironment::Production, $admin);
        $this->assertSame('paused', $manual->payload['status']);
        $this->assertFalse($manual->payload['immediatePause']);
    }

    public function test_active_admin_settings_and_publisher_domains_queue_new_versions(): void
    {
        [$site, $publisherUser, $admin] = $this->siteAndActors();
        $this->activate($site, $publisherUser, $admin);

        $this->actingAs($admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put(route('admin.sites.config.update', $site), [
                'debug_enabled' => 1,
                'house_ad_testing' => 0,
                'single_request_mode' => 1,
                'cache_ttl_seconds' => 120,
            ])
            ->assertRedirect();

        $settingsVersion = ConfigVersion::withoutGlobalScopes()->where('site_id', $site->id)->latest('version')->firstOrFail();
        $this->assertSame(2, $settingsVersion->version);
        $this->assertTrue($settingsVersion->payload['debug']);

        $this->actingAs($publisherUser)
            ->post(route('publisher.sites.domains.store', $site), ['domain' => 'cdn.publisher-example.test'])
            ->assertRedirect();

        $domainVersion = ConfigVersion::withoutGlobalScopes()->where('site_id', $site->id)->latest('version')->firstOrFail();
        $this->assertSame(3, $domainVersion->version);
        $this->assertContains('cdn.publisher-example.test', $domainVersion->payload['allowedHostnames']);
    }

    private function activate(Site $site, User $publisherUser, User $admin): void
    {
        $lifecycle = app(SiteLifecycleService::class);
        $lifecycle->transition($site, SiteStatus::PendingReview, $publisherUser, 'Ready for review.');
        $lifecycle->transition($site->refresh(), SiteStatus::Approved, $admin, 'Approved.');
        $lifecycle->transition($site->refresh(), SiteStatus::Active, $admin, 'Activated.');
    }

    private function siteAndActors(): array
    {
        $this->seedIdentity();
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($publisherUser), $publisherUser);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);

        return [$site, $publisherUser, $admin];
    }

    private function additionalSiteAndPublisher(): array
    {
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($publisherUser), $publisherUser);

        return [$site, $publisherUser];
    }
}
