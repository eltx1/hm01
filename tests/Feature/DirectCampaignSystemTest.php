<?php

namespace Tests\Feature;

use App\Enums\CampaignNetworkStatus;
use App\Enums\CampaignStatus;
use App\Enums\GamConnectionType;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Models\Advertiser;
use App\Models\Campaign;
use App\Models\CampaignNetworkInstance;
use App\Models\GamRemoteObject;
use App\Services\Campaigns\AdvertiserAccountService;
use App\Services\Campaigns\CampaignCreativeService;
use App\Services\Campaigns\CampaignDeploymentService;
use App\Services\Campaigns\CampaignNetworkPlanner;
use App\Services\Campaigns\CampaignReportingService;
use App\Services\Campaigns\CampaignWorkflowService;
use App\Services\Inventory\InventoryManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;
use ZipArchive;

class DirectCampaignSystemTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_one_local_campaign_deploys_repeat_safely_to_horus_and_selected_partner_gam(): void
    {
        [$campaign, $admin, $horus, $partner] = $this->campaignAcrossTwoNetworks('MOCK');
        $planner = app(CampaignNetworkPlanner::class);
        $preview = $planner->preview($campaign);

        $this->assertSame(2, $preview['networkInstances']);
        $this->assertSame([], $preview['issues']);
        $types = collect($preview['plans'])->pluck('networkType')->sort()->values()->all();
        $this->assertSame(['HORUS_GAM', 'MCM_PARTNER_GAM'], $types);

        $deployment = app(CampaignDeploymentService::class);
        $first = $deployment->deployCampaign($campaign, $admin, false, true);
        $this->assertFalse(collect($first['results'])->contains(fn (array $row) => ! $row['success']));
        $remoteCount = GamRemoteObject::withoutGlobalScopes()
            ->whereIn('gam_connection_id', [$horus->id, $partner->id])
            ->whereIn('local_object_type', ['advertiser', 'campaign', 'campaign_network_instance', 'campaign_creative', 'campaign_creative_association'])
            ->count();
        $this->assertSame(10, $remoteCount);

        $second = $deployment->deployCampaign($campaign->fresh(), $admin, false, true);
        $this->assertFalse(collect($second['results'])->contains(fn (array $row) => ! $row['success']));
        $this->assertSame($remoteCount, GamRemoteObject::withoutGlobalScopes()
            ->whereIn('gam_connection_id', [$horus->id, $partner->id])
            ->whereIn('local_object_type', ['advertiser', 'campaign', 'campaign_network_instance', 'campaign_creative', 'campaign_creative_association'])
            ->count());

        $workflow = app(CampaignWorkflowService::class);
        $paused = $workflow->pause($campaign->fresh(), $admin);
        $results = $deployment->pauseAll($paused->load('networkInstances.connection'), $admin);
        $this->assertNotContains(false, $results);
        $this->assertSame(2, CampaignNetworkInstance::withoutGlobalScopes()->where('campaign_id', $campaign->id)->where('status', CampaignNetworkStatus::Paused->value)->count());

        $instances = CampaignNetworkInstance::withoutGlobalScopes()->where('campaign_id', $campaign->id)->get();
        $reporting = app(CampaignReportingService::class);
        $reporting->recordAggregated($instances[0], [['report_date' => now()->toDateString(), 'external_report_id' => 'a', 'impressions' => 100, 'clicks' => 4, 'views' => 2, 'spend_minor' => 250]]);
        $reporting->recordAggregated($instances[1], [['report_date' => now()->toDateString(), 'external_report_id' => 'b', 'impressions' => 200, 'clicks' => 6, 'views' => 5, 'spend_minor' => 450]]);
        $summary = $reporting->summary($campaign);
        $this->assertSame(300, $summary['impressions']);
        $this->assertSame(10, $summary['clicks']);
        $this->assertSame(700, $summary['spend_minor']);
        $this->assertDatabaseHas('advertiser_invoices', ['campaign_id' => $campaign->id, 'status' => 'ISSUED']);
    }

    public function test_one_network_failure_is_isolated_and_retry_does_not_duplicate_horus_objects(): void
    {
        [$campaign, $admin, $horus, $partner] = $this->campaignAcrossTwoNetworks('REST');
        $deployment = app(CampaignDeploymentService::class);
        $result = $deployment->deployCampaign($campaign, $admin, false, true);
        $horusInstance = CampaignNetworkInstance::withoutGlobalScopes()->where('campaign_id', $campaign->id)->where('gam_connection_id', $horus->id)->firstOrFail();
        $partnerInstance = CampaignNetworkInstance::withoutGlobalScopes()->where('campaign_id', $campaign->id)->where('gam_connection_id', $partner->id)->firstOrFail();
        $this->assertTrue($result['results'][$horusInstance->id]['success']);
        $this->assertFalse($result['results'][$partnerInstance->id]['success']);
        $horusCount = GamRemoteObject::withoutGlobalScopes()->where('gam_connection_id', $horus->id)->count();
        $this->assertGreaterThan(1, $horusCount);
        $this->assertSame(0, GamRemoteObject::withoutGlobalScopes()->where('gam_connection_id', $partner->id)->where('local_object_type', 'campaign')->count());

        $partner->update(['driver' => 'MOCK']);
        $retry = $deployment->deployInstance($partnerInstance->fresh(), $admin, false, true);
        $this->assertTrue($retry['success']);
        $this->assertSame($horusCount, GamRemoteObject::withoutGlobalScopes()->where('gam_connection_id', $horus->id)->count());
        $this->assertDatabaseHas('campaign_network_instances', ['id' => $partnerInstance->id, 'status' => CampaignNetworkStatus::Active->value]);
    }

    public function test_creative_validation_rejects_unsafe_html_missing_assets_and_duplicate_files(): void
    {
        Storage::fake('local');
        [$campaign, $advertiserUser] = $this->draftCampaign();
        $service = app(CampaignCreativeService::class);

        $creative = $service->create($campaign, [
            'name' => 'Image 300x250', 'type' => 'IMAGE', 'click_through_url' => 'https://example.test/landing',
        ], UploadedFile::fake()->image('creative.png', 300, 250), $advertiserUser);
        $this->assertSame(300, $creative->width);
        $this->assertSame(250, $creative->height);
        $this->assertDatabaseHas('creative_files', ['campaign_creative_id' => $creative->id, 'mime_type' => 'image/png']);

        $stored = $creative->files->first();
        $duplicatePath = tempnam(sys_get_temp_dir(), 'hmdup');
        file_put_contents($duplicatePath, Storage::disk('local')->get($stored->path));
        try {
            $service->create($campaign, [
                'name' => 'Duplicate image', 'type' => 'IMAGE', 'click_through_url' => 'https://example.test/landing',
            ], new UploadedFile($duplicatePath, 'copy.png', 'image/png', null, true), $advertiserUser);
            $this->fail('Duplicate creative files should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('creative_file', $exception->errors());
        }

        try {
            $service->create($campaign, [
                'name' => 'Unsafe tag', 'type' => 'THIRD_PARTY_TAG', 'click_through_url' => 'https://example.test',
                'html_content' => '<script>document.cookie="steal"</script>',
            ], null, $advertiserUser);
            $this->fail('Unsafe HTML should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('html_content', $exception->errors());
        }

        try {
            $service->create($campaign, [
                'name' => 'Private URL tag', 'type' => 'THIRD_PARTY_TAG', 'click_through_url' => 'https://example.test',
                'html_content' => '<script src="https://127.0.0.1/creative.js"></script>',
            ], null, $advertiserUser);
            $this->fail('Private creative resources should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('html_content', $exception->errors());
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'hmzip');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $zip->addFromString('index.html', '<img src="missing.png">');
        $zip->close();
        try {
            $service->create($campaign, ['name' => 'Broken HTML5', 'type' => 'HTML5', 'click_through_url' => 'https://example.test'], new UploadedFile($zipPath, 'creative.zip', 'application/zip', null, true), $advertiserUser);
            $this->fail('Missing HTML5 assets should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('creative_file', $exception->errors());
        }

        config(['campaigns.html5_archive.max_compression_ratio' => 2]);
        $bombPath = tempnam(sys_get_temp_dir(), 'hmbomb');
        $bomb = new ZipArchive;
        $bomb->open($bombPath, ZipArchive::OVERWRITE);
        $bomb->addFromString('index.html', str_repeat('A', 100_000));
        $bomb->close();
        try {
            $service->create($campaign, ['name' => 'Compressed bomb', 'type' => 'HTML5', 'click_through_url' => 'https://example.test'], new UploadedFile($bombPath, 'bomb.zip', 'application/zip', null, true), $advertiserUser);
            $this->fail('Excessive ZIP compression should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('creative_file', $exception->errors());
        }
    }

    private function campaignAcrossTwoNetworks(string $partnerDriver): array
    {
        $this->seedIdentity();
        $horusOrg = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $admin = $this->makeUser($horusOrg, RoleName::SuperAdmin);
        $horus = $this->makeGamConnection($horusOrg, $admin, ['type' => GamConnectionType::HorusGam, 'driver' => 'MOCK', 'network_code' => '111111111', 'is_primary' => true, 'dry_run_default' => false]);
        $partnerOrg = $this->makeOrganization(OrganizationType::Partner, 'MCM Partner');
        $partner = $this->makeGamConnection($partnerOrg, $admin, ['type' => GamConnectionType::McmPartnerGam, 'driver' => $partnerDriver, 'network_code' => '222222222', 'is_primary' => false, 'dry_run_default' => false]);

        $publisherUser1 = $this->makeUser($this->makeOrganization(OrganizationType::Publisher, 'Publisher One'), RoleName::PublisherAdmin);
        $publisher1 = $this->makePublisherFor($publisherUser1, ['display_name' => 'Publisher One']);
        $site1 = $this->makeSiteFor($publisher1, $publisherUser1, ['display_name' => 'Horus Site', 'primary_domain' => 'horus-site.test']);

        $publisherUser2 = $this->makeUser($this->makeOrganization(OrganizationType::Publisher, 'Publisher Two'), RoleName::PublisherAdmin);
        $publisher2 = $this->makePublisherFor($publisherUser2, ['display_name' => 'Publisher Two']);
        $site2 = $this->makeSiteFor($publisher2, $publisherUser2, ['display_name' => 'Partner Site', 'primary_domain' => 'partner-site.test']);
        $site2->update(['serving_mode' => ServingMode::McmPartnerGam, 'gam_connection_id' => $partner->id]);

        $inventory = app(InventoryManager::class);
        $adUnit1 = $inventory->createAdUnit($site1, ['name' => 'Top', 'code' => 'top', 'sizes' => [['width' => 300, 'height' => 250]]], $admin);
        $placement1 = $inventory->createPlacement($site1, ['name' => 'Top', 'code' => 'top', 'type' => 'DISPLAY', 'status' => 'ACTIVE', 'ad_unit_id' => $adUnit1->id, 'sizes' => [['width' => 300, 'height' => 250]]], $admin);
        $adUnit2 = $inventory->createAdUnit($site2, ['name' => 'Top', 'code' => 'top', 'sizes' => [['width' => 300, 'height' => 250]]], $admin);
        $placement2 = $inventory->createPlacement($site2, ['name' => 'Top', 'code' => 'top', 'type' => 'DISPLAY', 'status' => 'ACTIVE', 'ad_unit_id' => $adUnit2->id, 'sizes' => [['width' => 300, 'height' => 250]]], $admin);
        $this->mapAdUnit($horus, $adUnit1->id, '5101');
        $this->mapAdUnit($partner, $adUnit2->id, '5201');

        $advertiserOrg = $this->makeOrganization(OrganizationType::Advertiser, 'Direct Advertiser');
        $advertiserUser = $this->makeUser($advertiserOrg, RoleName::AdvertiserAdmin);
        $advertiser = Advertiser::withoutGlobalScopes()->create(['organization_id' => $advertiserOrg->id, 'legal_name' => 'Direct Advertiser LLC', 'display_name' => 'Direct Advertiser', 'status' => 'ACTIVE', 'billing_email' => 'billing@advertiser.test']);
        $accounts = app(AdvertiserAccountService::class);
        $accounts->linkUser($advertiser, $advertiserUser, 'OWNER', true, $admin);
        $accounts->saveBillingProfile($advertiser, [
            'legal_name' => 'Direct Advertiser LLC', 'billing_email' => 'billing@advertiser.test',
            'currency' => 'USD', 'country_code' => 'EG', 'address_line_1' => 'Cairo', 'city' => 'Cairo',
            'payment_terms_days' => 15, 'is_default' => true, 'status' => 'ACTIVE',
        ], $advertiserUser);
        $workflow = app(CampaignWorkflowService::class);
        $campaign = $workflow->create($advertiser, [
            'name' => 'Multi-network launch', 'objective' => 'Awareness', 'pricing_model' => 'CPM',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(10), 'currency' => 'USD',
            'total_budget_minor' => 100000, 'daily_budget_minor' => 10000, 'unit_price_minor' => 250,
            'impression_goal' => 100000, 'click_goal' => 1000, 'countries' => [], 'devices' => [],
            'site_ids' => [$site1->id, $site2->id], 'placement_ids' => [$placement1->id, $placement2->id],
            'frequency_cap_impressions' => 3, 'frequency_cap_days' => 1, 'landing_url' => 'https://advertiser.test/landing',
        ], $advertiserUser);
        $creative = app(CampaignCreativeService::class)->create($campaign, ['name' => 'Text creative', 'type' => 'TEXT', 'text_content' => 'Discover the offer', 'click_through_url' => 'https://advertiser.test/landing'], null, $advertiserUser);
        $campaign = $workflow->submit($campaign, $advertiserUser);
        $workflow->reviewCreative($creative->fresh(), true, $admin);
        $campaign = $workflow->approve($campaign->fresh(), $admin);
        $campaign = $workflow->schedule($campaign, $admin);
        $this->assertSame(CampaignStatus::Active, $campaign->status);

        return [$campaign->load(['sites.site', 'placements.placement', 'creatives.files', 'budget', 'targets', 'networkInstances']), $admin, $horus, $partner];
    }

    private function draftCampaign(): array
    {
        $this->seedIdentity();
        $advertiserOrg = $this->makeOrganization(OrganizationType::Advertiser, 'Creative Advertiser');
        $user = $this->makeUser($advertiserOrg, RoleName::AdvertiserAdmin);
        $advertiser = Advertiser::withoutGlobalScopes()->create(['organization_id' => $advertiserOrg->id, 'legal_name' => 'Creative LLC', 'display_name' => 'Creative', 'status' => 'ACTIVE']);
        $campaign = Campaign::withoutGlobalScopes()->create([
            'public_key' => 'HC_TEST_CREATIVE', 'organization_id' => $advertiserOrg->id, 'advertiser_id' => $advertiser->id,
            'name' => 'Creative test', 'objective' => 'Test', 'pricing_model' => 'CPM', 'status' => 'DRAFT',
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2), 'currency' => 'USD', 'total_budget_minor' => 1000,
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        return [$campaign, $user];
    }

    private function mapAdUnit($connection, string $localId, string $remoteId): void
    {
        GamRemoteObject::withoutGlobalScopes()->create([
            'organization_id' => $connection->organization_id, 'gam_connection_id' => $connection->id,
            'local_object_type' => 'ad_unit', 'local_object_id' => $localId, 'remote_object_type' => 'ad_unit',
            'remote_object_id' => $remoteId, 'idempotency_key' => 'test-'.$remoteId, 'payload_hash' => hash('sha256', $remoteId), 'remote_status' => 'ACTIVE', 'synced_at' => now(),
        ]);
    }
}
