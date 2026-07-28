<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\GamConnectionType;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\PrebidAdapter;
use App\Models\PrebidBuild;
use App\Models\PrebidGamRemoteObject;
use App\Models\PrebidSetting;
use App\Services\Gam\Contracts\GamSoapTransportInterface;
use App\Services\Gam\GamConnectionService;
use App\Services\Inventory\InventoryManager;
use App\Services\Inventory\SiteConfigurationBuilder;
use App\Services\Prebid\PrebidConfigurationService;
use App\Services\Prebid\PrebidGamSetupService;
use Database\Seeders\PrebidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\Fakes\FakeGamSoapTransport;
use Tests\TestCase;

class PrebidIntegrationTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private FakeGamSoapTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new FakeGamSoapTransport;
        $this->app->instance(GamSoapTransportInterface::class, $this->transport);
    }

    public function test_browser_configuration_uses_only_the_selected_gam_network_mappings(): void
    {
        [$site, $publisherUser, $admin, $horus, $partner] = $this->environment();
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site, [
            'name' => 'Article Top', 'code' => 'article_top',
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $publisherUser);
        $placement = $inventory->createPlacement($site, [
            'name' => 'Article Top', 'code' => 'article_top', 'type' => 'DISPLAY', 'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id, 'sizes' => [['width' => 300, 'height' => 250]],
        ], $publisherUser);

        $prebid = app(PrebidConfigurationService::class);
        $adapter = PrebidAdapter::query()->where('bidder_code', 'pubmatic')->firstOrFail();
        $account = $prebid->createAccount($site, [
            'prebid_adapter_id' => $adapter->id,
            'name' => 'PubMatic main',
            'publisher_id' => '12345',
            'public_parameters' => [],
            'enabled' => true,
        ], $admin);
        $prebid->assignToSite($account, $site, null, [
            'sequence' => 10, 'enabled' => true, 'public_parameters' => ['adSlot' => 'horus-slot'],
        ], $admin);
        $prebid->saveSettings($site, $horus, $this->settings(), $admin);

        $central = app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 1);
        $this->assertTrue($central['prebid']['enabled']);
        $this->assertSame('HORUS_GAM', $central['prebid']['gamConnectionType']);
        $this->assertSame('horus-slot', $central['placements'][0]['prebid']['bids'][0]['params']['adSlot']);
        $this->assertSame('/123456789/article_top', $central['placements'][0]['adUnitPath']);

        app(GamConnectionService::class)->assignToSite($site, $partner, $admin, 'Select partner GAM for this site.');
        $prebid->saveSettings($site->refresh(), $partner, $this->settings(), $admin);
        $withoutPartnerMapping = app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 2);
        $this->assertFalse($withoutPartnerMapping['prebid']['enabled']);
        $this->assertSame([], $withoutPartnerMapping['placements'][0]['prebid']['bids']);

        $prebid->assignToPlacement($account, $placement, $partner, [
            'enabled' => true, 'public_parameters' => ['adSlot' => 'partner-placement'],
        ], $admin);
        $partnerConfig = app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 3);
        $this->assertTrue($partnerConfig['prebid']['enabled']);
        $this->assertSame('MCM_PARTNER_GAM', $partnerConfig['prebid']['gamConnectionType']);
        $this->assertSame('partner-placement', $partnerConfig['placements'][0]['prebid']['bids'][0]['params']['adSlot']);
        $this->assertSame('/987654321/article_top', $partnerConfig['placements'][0]['adUnitPath']);
        $this->assertStringNotContainsString('12345', $site->installationCode());
    }

    public function test_central_gam_setup_is_dry_run_safe_confirmed_resumable_and_idempotent(): void
    {
        [$site, , $admin, $horus] = $this->environment();
        $setup = app(PrebidGamSetupService::class);
        $template = $setup->ensureTemplate($horus, $admin);
        $template->priceBucket->update(['ranges' => [['min' => 0, 'max' => 0.10, 'increment' => 0.05, 'precision' => 2]]]);

        $preview = $setup->preview($template->refresh(), $admin);
        $this->assertSame('PREVIEW', $preview->status);
        $this->assertGreaterThan(0, $preview->estimated_objects);
        $this->assertCount(0, $this->transport->calls);

        try {
            $setup->execute($preview, $admin, false);
            $this->fail('External GAM writes must require administrator confirmation.');
        } catch (ValidationException) {
            $this->assertCount(0, $this->transport->calls);
        }

        $partial = $setup->execute($preview->refresh(), $admin, true, 2);
        $this->assertSame('PARTIAL', $partial->status);
        $this->assertSame(2, data_get($partial->cursor, 'offset'));
        $completed = $setup->resume($partial->refresh(), $admin, 500);
        $this->assertSame('SUCCEEDED', $completed->status);
        $firstCallCount = count($this->transport->calls);
        $this->assertSame($completed->counters['total'], PrebidGamRemoteObject::withoutGlobalScopes()->where('gam_connection_id', $horus->id)->count());

        $secondPreview = $setup->preview($template->refresh(), $admin);
        $this->assertSame(0, $secondPreview->estimated_objects);
        $secondRun = $setup->execute($secondPreview, $admin, true, 500);
        $this->assertSame('SUCCEEDED', $secondRun->status);
        $this->assertSame($firstCallCount, count($this->transport->calls));
        $this->assertSame($completed->counters['total'], PrebidGamRemoteObject::withoutGlobalScopes()->where('gam_connection_id', $horus->id)->count());
    }

    public function test_incomplete_gam_connection_is_detected_before_external_writes(): void
    {
        [, , $admin, , $partner] = $this->environment();
        $partner->update(['configuration' => ['root_ad_unit_id' => null, 'trafficker_id' => null]]);
        $setup = app(PrebidGamSetupService::class);
        $preview = $setup->preview($setup->ensureTemplate($partner->refresh(), $admin), $admin);

        $this->expectException(ValidationException::class);
        try {
            $setup->execute($preview, $admin, true);
        } finally {
            $this->assertCount(0, $this->transport->calls);
        }
    }

    private function environment(): array
    {
        $this->seedIdentity();
        $this->seed(PrebidSeeder::class);
        PrebidBuild::query()->updateOrCreate(['version' => 'test'], [
            'name' => 'Test build', 'source_ref' => 'test', 'source_url' => 'https://github.com/prebid/Prebid.js',
            'asset_path' => 'assets/prebid/prebid-test.js', 'minified_path' => 'assets/prebid/prebid-test.min.js',
            'manifest_path' => 'assets/prebid/prebid-test.manifest.json', 'checksum' => str_repeat('a', 64),
            'modules' => ['pubmaticBidAdapter'], 'adapters' => ['pubmatic'], 'status' => 'READY',
            'is_active' => true, 'built_at' => now(),
        ]);

        $horusOrganization = $this->makeOrganization(OrganizationType::HorusMedia);
        $admin = $this->makeUser($horusOrganization, RoleName::SuperAdmin);
        $horus = $this->makeGamConnection($horusOrganization, $admin, [
            'type' => GamConnectionType::HorusGam,
            'network_code' => '123456789',
            'is_primary' => true,
            'configuration' => ['root_ad_unit_id' => '1111', 'trafficker_id' => '2222'],
        ]);
        $partner = $this->makeGamConnection($horusOrganization, $admin, [
            'type' => GamConnectionType::McmPartnerGam,
            'network_code' => '987654321',
            'is_primary' => false,
            'configuration' => ['root_ad_unit_id' => '3333', 'trafficker_id' => '4444'],
        ]);
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($publisherUser), $publisherUser, ['primary_domain' => 'publisher.example']);

        return [$site, $publisherUser, $admin, $horus, $partner];
    }

    private function settings(): array
    {
        return [
            'enabled' => true,
            'auction_timeout_ms' => 900,
            'price_granularity' => 'custom',
            'currency_code' => 'USD',
            'bidder_sequence' => 'fixed',
            'consent_behavior' => ['gdpr' => ['cmpApi' => 'iab', 'timeout' => 800, 'allowAuctionWithoutConsent' => false]],
            'lazy_loading' => ['enabled' => true],
            'refresh_behavior' => ['enabled' => true, 'auctionBeforeRefresh' => true],
            'timeout_reporting' => true,
            'gam_fallback' => true,
        ];
    }
}
