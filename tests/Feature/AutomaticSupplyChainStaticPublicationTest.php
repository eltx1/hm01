<?php

namespace Tests\Feature;

use App\Enums\AdsTxtDeploymentMode;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\ConfigVersion;
use App\Models\PlatformAdsTxtRecord;
use App\Models\StaticGlobalArtifactChange;
use App\Services\StaticDelivery\Contracts\StaticDeliveryDriverInterface;
use App\Services\StaticDelivery\StaticDeliveryManager;
use App\Services\StaticDelivery\StaticDeliverySnapshotBuilder;
use App\Services\StaticDelivery\SupplyChainStaticPublisher;
use App\Services\SupplyChain\ManagedAdsTxtDelegationService;
use App\Services\SupplyChain\SupplyChainArtifactBuilder;
use App\Services\SupplyChain\SupplyChainPublicOriginVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\Fakes\FakeStaticDeliveryDriver;
use Tests\TestCase;

class AutomaticSupplyChainStaticPublicationTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private FakeStaticDeliveryDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'static-delivery.normal_batch_interval_minutes' => 30,
            'static-delivery.monthly_deployment_budget' => 50,
            'static-delivery.emergency_reserve' => 5,
            'static-delivery.file_budget.warning_threshold' => 1000,
            'static-delivery.file_budget.hard_limit' => 2000,
            'supply-chain.managed_ads_txt_base_url' => 'https://cdn.horusmedia.net',
            'supply-chain.canonical_sellers_json_url' => 'https://horusmedia.net/sellers.json',
            'supply-chain.sellers_json_proxy_target' => 'https://cdn.horusmedia.net/sellers.json',
        ]);
        $this->driver = new FakeStaticDeliveryDriver;
        $this->app->instance(StaticDeliveryDriverInterface::class, $this->driver);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_ten_supply_chain_changes_coalesce_without_config_version_explosion_and_wait_for_boundary(): void
    {
        CarbonImmutable::setTestNow('2026-08-16 12:01:00 UTC');
        $publisher = app(SupplyChainStaticPublisher::class);
        foreach (range(1, 10) as $index) {
            $publisher->queueNormal(['event' => 'CHANGE_'.$index]);
        }

        $this->assertSame(1, StaticGlobalArtifactChange::query()->count());
        $change = StaticGlobalArtifactChange::query()->firstOrFail();
        $this->assertSame(10, $change->event_count);
        $this->assertSame('SUPPLY_CHAIN', $change->artifact_type);
        $this->assertSame('NORMAL', $change->priority->value);
        $this->assertSame('2026-08-16 12:30:00', $change->available_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(0, ConfigVersion::withoutGlobalScopes()->count());
        $this->assertNull(app(StaticDeliveryManager::class)->processPending());

        CarbonImmutable::setTestNow('2026-08-16 12:30:00 UTC');
        $batch = app(StaticDeliveryManager::class)->processPending();
        $this->assertNotNull($batch);
        $this->assertSame('DEPLOYED', $batch->status->value);
        $this->assertSame(1, $batch->item_count);
        $this->assertSame(0, ConfigVersion::withoutGlobalScopes()->count());
        $this->assertArrayHasKey('sellers.json', $this->driver->snapshots[0]->files);
        $this->assertArrayHasKey('supply/sellers.json', $this->driver->snapshots[0]->files);
    }

    public function test_urgent_revocation_upgrades_pending_change_and_bypasses_boundary(): void
    {
        CarbonImmutable::setTestNow('2026-08-16 12:01:00 UTC');
        $publisher = app(SupplyChainStaticPublisher::class);
        $publisher->queueNormal(['event' => 'NORMAL']);
        $publisher->queueUrgent(['event' => 'REVOCATION']);

        $change = StaticGlobalArtifactChange::query()->firstOrFail();
        $this->assertSame(1, StaticGlobalArtifactChange::query()->count());
        $this->assertSame(2, $change->event_count);
        $this->assertSame('URGENT', $change->priority->value);
        $this->assertTrue($change->available_at->lessThanOrEqualTo(now()));
        $this->assertSame('DEPLOYED', app(StaticDeliveryManager::class)->processPending()->status->value);
    }

    public function test_master_record_create_and_disable_automatically_queue_normal_then_urgent_publication(): void
    {
        CarbonImmutable::setTestNow('2026-08-16 12:01:00 UTC');
        $record = PlatformAdsTxtRecord::create([
            'advertising_system_domain' => 'master.example.com',
            'publisher_account_id' => 'seat-37',
            'relationship' => 'DIRECT',
            'raw_record' => 'master.example.com, seat-37, DIRECT',
            'record_hash' => hash('sha256', 'master.example.com, seat-37, DIRECT'),
            'status' => 'ACTIVE',
            'review_status' => 'VERIFIED',
        ]);
        $this->assertSame('NORMAL', StaticGlobalArtifactChange::query()->firstOrFail()->priority->value);

        StaticGlobalArtifactChange::query()->delete();
        $record->update(['status' => 'DISABLED']);
        $change = StaticGlobalArtifactChange::query()->firstOrFail();
        $this->assertSame('URGENT', $change->priority->value);
        $this->assertTrue($change->available_at->lessThanOrEqualTo(now()));
    }

    public function test_supply_chain_snapshot_is_deterministic_and_managed_paths_and_content_headers_exist(): void
    {
        [$site] = $this->site();
        $files = app(SupplyChainArtifactBuilder::class)->files();
        $this->assertArrayHasKey('sellers.json', $files);
        $this->assertArrayHasKey('supply/sellers.json', $files);
        $this->assertArrayHasKey('supply/domains/publisher.example/ads.txt', $files);

        $first = app(StaticDeliverySnapshotBuilder::class)->build();
        $second = app(StaticDeliverySnapshotBuilder::class)->build();
        $this->assertSame($first->manifestHash, $second->manifestHash);
        $headers = $first->files['_headers'];
        $this->assertStringContainsString('/sellers.json', $headers);
        $this->assertStringContainsString('Content-Type: application/json; charset=utf-8', $headers);
        $this->assertStringContainsString('Content-Type: text/plain; charset=utf-8', $headers);
    }

    public function test_managed_redirect_requires_one_hop_and_exact_generated_text_payload(): void
    {
        [$site] = $this->site();
        $settings = $site->servingSettings()->firstOrFail();
        $settings->update(['ads_txt_deployment_mode' => AdsTxtDeploymentMode::ManagedRedirectDelegation]);
        $service = app(ManagedAdsTxtDelegationService::class);
        $target = $service->managedUrlForSite($site);
        $payload = app(SupplyChainArtifactBuilder::class)->adsTxtForSite($site);

        Http::fake([
            'https://publisher.example/ads.txt' => Http::response('', 302, ['Location' => $target]),
            $target => Http::response($payload, 200, ['Content-Type' => 'text/plain; charset=utf-8']),
        ]);
        $this->assertTrue($service->verify($site)['valid']);

        Http::fake([
            'https://publisher.example/ads.txt' => Http::response('', 302, ['Location' => $target]),
            $target => Http::response('', 302, ['Location' => 'https://third.example/ads.txt']),
        ]);
        $this->assertSame('ADS_TXT_REDIRECT_CHAIN_INVALID', $service->verify($site)['code']);
    }

    public function test_canonical_sellers_json_origin_must_match_current_payload_and_cdn_only_is_not_enough(): void
    {
        $verifier = app(SupplyChainPublicOriginVerifier::class);
        $payload = app(SupplyChainArtifactBuilder::class)->sellersJson();
        $this->assertFalse($verifier->readiness()['verified']);

        Http::fake([
            'https://horusmedia.net/sellers.json' => Http::response('', 302, ['Location' => 'https://cdn.horusmedia.net/sellers.json']),
            'https://cdn.horusmedia.net/sellers.json' => Http::response($payload, 200, ['Content-Type' => 'application/json; charset=utf-8']),
        ]);
        $result = $verifier->verifySellersJson();
        $this->assertTrue($result['verified']);
        $this->assertTrue($verifier->readiness()['verified']);

        Http::fake([
            'https://horusmedia.net/sellers.json' => Http::response('', 404),
            'https://cdn.horusmedia.net/sellers.json' => Http::response($payload, 200, ['Content-Type' => 'application/json']),
        ]);
        $this->assertFalse($verifier->verifySellersJson()['verified']);
    }

    private function site(): array
    {
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, ['business_domain' => 'publisher.example']);
        $site = $this->makeSiteFor($publisher, $publisherUser, ['primary_domain' => 'publisher.example']);
        StaticGlobalArtifactChange::query()->delete();

        return [$site, $admin];
    }
}
