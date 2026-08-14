<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SellerDeclarationStatus;
use App\Enums\SupplyChainReviewStatus;
use App\Models\DemandAccount;
use App\Models\DemandAdsTxtRecord;
use App\Models\DemandNetwork;
use App\Models\DemandSite;
use App\Models\SellerDeclaration;
use App\Models\Site;
use App\Models\StaticDeliveryItem;
use App\Services\Compliance\SellerDeclarationManager;
use App\Services\Compliance\SupplyChainComplianceService;
use App\Services\Inventory\SiteConfigurationBuilder;
use App\Services\StaticDelivery\StaticDeliverySnapshotBuilder;
use App\Services\SupplyChain\SellersJsonValidator;
use App\Services\SupplyChain\SupplyChainArtifactBuilder;
use App\Services\SupplyChain\SupplyChainInvariantService;
use App\Services\SupplyChain\SupplyChainObjectValidator;
use Database\Seeders\DemandNetworkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class SupplyChainComplianceTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;

    private $publisherUser;

    private $publisher;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['static-delivery.normal_batch_interval_minutes' => 0]);
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Publisher One');
        $this->publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser, [
            'legal_name' => 'Publisher One LLC',
            'display_name' => 'Publisher One',
            'business_domain' => 'publisher-one-owner.example',
        ]);
        $this->site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Publisher One News',
            'primary_domain' => 'news.publisher-one.example',
        ]);
    }

    public function test_complete_sellers_json_schema_supports_all_types_confidentiality_ordering_and_root_alias(): void
    {
        $publisher = $this->seller('z-publisher', 'PUBLISHER');
        $both = $this->seller('a-both', 'BOTH');
        $intermediary = $this->seller('m-intermediary', 'INTERMEDIARY', true);

        $payload = app(SupplyChainArtifactBuilder::class)->sellersJsonPayload();
        $artifact = app(SupplyChainArtifactBuilder::class)->sellersJson();
        $files = app(StaticDeliverySnapshotBuilder::class)->build()->files;

        $this->assertSame([], app(SellersJsonValidator::class)->validate($payload));
        $this->assertSame(['a-both', 'm-intermediary', 'z-publisher'], array_column($payload['sellers'], 'seller_id'));
        $this->assertSame(['BOTH', 'INTERMEDIARY', 'PUBLISHER'], array_column($payload['sellers'], 'seller_type'));
        $confidential = collect($payload['sellers'])->firstWhere('seller_id', 'm-intermediary');
        $this->assertSame(1, $confidential['is_confidential']);
        $this->assertArrayNotHasKey('name', $confidential);
        $this->assertArrayNotHasKey('domain', $confidential);
        $this->assertSame($artifact, $files['sellers.json']);
        $this->assertSame($artifact, $files['supply/sellers.json']);
        $this->assertSame($publisher->seller_id, 'z-publisher');
        $this->assertSame($both->seller_id, 'a-both');
        $this->assertSame($intermediary->seller_id, 'm-intermediary');
    }

    public function test_disabled_sellers_are_never_emitted_and_invalid_complete_artifacts_are_rejected(): void
    {
        $disabled = app(SupplyChainInvariantService::class)->saveSellerDeclaration($this->publisher, null, [
            'seller_id' => 'disabled-seller', 'seller_type' => 'PUBLISHER', 'name' => 'Publisher One LLC',
            'domain' => 'publisher-one-owner.example', 'is_confidential' => false, 'status' => 'DISABLED',
        ], $this->admin);
        $payload = app(SupplyChainArtifactBuilder::class)->sellersJsonPayload();

        $this->assertSame(SellerDeclarationStatus::Disabled, $disabled->status);
        $this->assertSame([], $payload['sellers']);
        $errors = app(SellersJsonValidator::class)->validate([
            'version' => '1.0',
            'sellers' => [
                ['seller_id' => 'duplicate', 'seller_type' => 'PUBLISHER', 'is_confidential' => 0, 'name' => 'One', 'domain' => 'one.example'],
                ['seller_id' => 'duplicate', 'seller_type' => 'PUBLISHER', 'is_confidential' => 0, 'name' => 'Two', 'domain' => 'two.example'],
            ],
        ]);
        $this->assertTrue(collect($errors)->contains(fn (string $error): bool => str_contains($error, 'appears more than once')));
    }

    public function test_manager_lifecycle_requires_review_audits_actions_and_queues_static_publication(): void
    {
        $manager = app(SellerDeclarationManager::class);
        $seller = $manager->create([
            'publisher_id' => $this->publisher->id,
            'site_id' => null,
            'seller_id' => 'lifecycle-1',
            'seller_type' => 'PUBLISHER',
            'name' => 'Publisher One LLC',
            'domain' => 'publisher-one-owner.example',
            'is_confidential' => false,
        ], $this->admin);

        $this->assertSame(SellerDeclarationStatus::Disabled, $seller->status);
        $this->assertSame(SupplyChainReviewStatus::ReviewRequired, $seller->review_status);
        try {
            $manager->activate($seller, $this->admin);
            $this->fail('Unverified seller activation should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $manager->review($seller, SupplyChainReviewStatus::Verified, $this->admin);
        $active = $manager->activate($seller->fresh(), $this->admin);
        $this->assertSame(SellerDeclarationStatus::Active, $active->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'supply_chain.seller.reviewed', 'auditable_id' => $seller->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'supply_chain.seller.activated', 'auditable_id' => $seller->id]);
        $this->assertGreaterThan(0, StaticDeliveryItem::withoutGlobalScopes()->count());

        $updated = $manager->update($active, [
            'site_id' => $this->site->id,
            'seller_id' => 'lifecycle-1',
            'seller_type' => 'BOTH',
            'name' => 'Publisher One Media LLC',
            'domain' => 'publisher-one-owner.example',
            'is_confidential' => false,
        ], $this->admin);
        $this->assertSame(SellerDeclarationStatus::Disabled, $updated->status);
        $this->assertSame(SupplyChainReviewStatus::ReviewRequired, $updated->review_status);
        $this->assertNull($updated->last_verified_at);
    }

    public function test_activation_fails_safely_when_no_website_can_trigger_static_publication(): void
    {
        $organization = $this->makeOrganization(OrganizationType::Publisher, 'No Site Publisher');
        $user = $this->makeUser($organization, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user, [
            'legal_name' => 'No Site Publisher LLC',
            'business_domain' => 'no-site-publisher.example',
        ]);
        $manager = app(SellerDeclarationManager::class);
        $seller = $manager->create([
            'publisher_id' => $publisher->id,
            'seller_id' => 'no-site-seller',
            'seller_type' => 'PUBLISHER',
            'name' => 'No Site Publisher LLC',
            'domain' => 'no-site-publisher.example',
            'is_confidential' => false,
        ], $this->admin);
        $manager->review($seller, SupplyChainReviewStatus::Verified, $this->admin);

        try {
            $manager->activate($seller->fresh(), $this->admin);
            $this->fail('A seller with no website publication trigger should not activate.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame(SellerDeclarationStatus::Disabled, $seller->fresh()->status);
    }

    public function test_admin_control_center_routes_permissions_exact_preview_and_history(): void
    {
        $seller = $this->seller('admin-ui', 'PUBLISHER');
        $admin = $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp]);

        $admin->get(route('admin.compliance.sellers.index'))->assertOk()
            ->assertSee('Supply Chain Control Center')->assertSee('admin-ui')->assertSee('sellers.json preview');
        $admin->get(route('admin.compliance.sellers.show', $seller))->assertOk()
            ->assertSee('Generated fragment')->assertSee('Seller history')->assertSee('news.publisher-one.example');
        $admin->get(route('admin.compliance.sellers.artifact'))->assertOk()
            ->assertHeader('Content-Type', 'application/json; charset=UTF-8')->assertSee('admin-ui');

        $support = $this->makeUser($this->admin->organization, RoleName::SupportAgent);
        $this->actingAs($support)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.compliance.sellers.index'))->assertOk();
        $this->actingAs($support)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.compliance.sellers.store'), [])->assertForbidden();
        $this->actingAs($this->publisherUser)->get(route('admin.compliance.sellers.index'))->assertForbidden();
    }

    public function test_publisher_sees_only_own_public_identity_and_no_other_confidential_data(): void
    {
        $own = $this->seller('publisher-own', 'BOTH');
        $otherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Other Publisher');
        $otherUser = $this->makeUser($otherOrganization, RoleName::PublisherAdmin);
        $otherPublisher = $this->makePublisherFor($otherUser, [
            'legal_name' => 'Other Secret Holdings LLC',
            'display_name' => 'Other Publisher',
            'business_domain' => 'other-publisher.example',
        ]);
        $this->makeSiteFor($otherPublisher, $otherUser, ['primary_domain' => 'other-site.example']);
        $other = $this->sellerFor($otherPublisher, 'other-secret-id', 'PUBLISHER', true);

        $response = $this->actingAs($this->publisherUser)->get(route('publisher.ads-txt.index'));
        $response->assertOk()->assertSee('publisher-own')->assertSee('Publisher One LLC')->assertSee('sellers.json &amp; schain health', false)
            ->assertDontSee('other-secret-id')->assertDontSee('Other Secret Holdings LLC')->assertDontSee('other-publisher.example');
        $this->assertSame('publisher-own', $own->seller_id);
        $this->assertSame('other-secret-id', $other->seller_id);
    }

    public function test_ads_txt_sellers_json_and_schain_cross_validation_share_the_same_identity(): void
    {
        $seller = $this->seller('canonical-42', 'PUBLISHER');
        $artifacts = app(SupplyChainArtifactBuilder::class);
        $adsTxt = $artifacts->adsTxtForSite($this->site);
        $sellers = $artifacts->sellersJsonPayload();
        $site = app(SupplyChainComplianceService::class)->siteOverview($this->site);

        $this->assertStringContainsString('horusmedia.net, canonical-42, DIRECT', $adsTxt);
        $this->assertSame('canonical-42', $sellers['sellers'][0]['seller_id']);
        $this->assertSame(['asi' => 'horusmedia.net', 'sid' => 'canonical-42', 'hp' => 1], $site['schain']['nodes'][0]);
        $this->assertSame(1, $site['schain']['complete']);
        $this->assertSame('HEALTHY', $site['ads_txt_health']);
        $this->assertSame('HEALTHY', $site['schain_health']);
        $this->assertSame($seller->seller_id, $site['seller_id']);
    }

    public function test_cross_validation_detects_unknown_ads_seller_and_intermediary_incompleteness(): void
    {
        $this->seed(DemandNetworkSeeder::class);
        $intermediary = $this->seller('intermediary-42', 'INTERMEDIARY');
        $account = DemandAccount::withoutGlobalScopes()->create([
            'organization_id' => $this->admin->organization_id,
            'demand_network_id' => DemandNetwork::query()->where('code', 'MGID')->value('id'),
            'name' => 'Horus canonical records',
            'scope' => DemandAccountScope::HorusMedia,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'revenue_share_percent' => 0,
            'fallback_priority' => 50,
        ]);
        DemandSite::withoutGlobalScopes()->create([
            'organization_id' => $this->site->organization_id,
            'demand_account_id' => $account->id,
            'site_id' => $this->site->id,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'integration_mode' => DemandIntegrationMode::DirectJs,
        ]);
        $normalized = app(SupplyChainInvariantService::class)->normalizeDemandRecord($account, $this->site, [
            'domain' => 'horusmedia.net', 'publisher_account_id' => 'unknown-seller', 'relationship' => 'DIRECT',
        ]);
        DemandAdsTxtRecord::withoutGlobalScopes()->create($normalized + [
            'demand_account_id' => $account->id,
            'site_id' => $this->site->id,
            'status' => 'ACTIVE',
            'source' => 'TEST',
        ]);

        $overview = app(SupplyChainComplianceService::class)->siteOverview($this->site);
        $codes = collect($overview['findings'])->pluck('code');
        $this->assertContains('ADS_TXT_UNKNOWN_HORUS_SELLER', $codes);
        $this->assertContains('ADS_TXT_SELLER_AUTHORIZATION_MISSING', $codes);
        $this->assertContains('SCHAIN_UPSTREAM_IDENTITY_REQUIRED', $codes);
        $this->assertSame(0, $overview['schain']['complete']);
        $this->assertSame('PARTIAL', $overview['schain_health']);
        $this->assertSame('intermediary-42', $intermediary->seller_id);
    }

    public function test_duplicate_equivalents_collapse_but_conflicting_legacy_identity_is_never_published(): void
    {
        $first = $this->seller('shared-identity', 'PUBLISHER');
        $duplicate = app(SupplyChainInvariantService::class)->saveSellerDeclaration($this->publisher, $this->site, [
            'seller_id' => 'shared-identity', 'seller_type' => 'PUBLISHER', 'name' => 'Publisher One LLC',
            'domain' => 'publisher-one-owner.example', 'is_confidential' => false,
        ], $this->admin);
        $payload = app(SupplyChainArtifactBuilder::class)->sellersJsonPayload();
        $this->assertCount(1, $payload['sellers']);

        $otherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Conflict Publisher');
        $otherUser = $this->makeUser($otherOrganization, RoleName::PublisherAdmin);
        $otherPublisher = $this->makePublisherFor($otherUser, ['business_domain' => 'conflict.example']);
        $otherSite = $this->makeSiteFor($otherPublisher, $otherUser, ['primary_domain' => 'site.conflict.example']);
        SellerDeclaration::withoutGlobalScopes()->create([
            'organization_id' => $otherPublisher->organization_id,
            'publisher_id' => $otherPublisher->id,
            'site_id' => $otherSite->id,
            'seller_id' => 'shared-identity',
            'seller_type' => 'PUBLISHER',
            'name' => 'Conflict Publisher LLC',
            'domain' => 'conflict.example',
            'status' => 'ACTIVE',
        ]);

        $network = app(SupplyChainInvariantService::class)->sellers();
        $this->assertSame([], $network['sellers']);
        $this->assertContains('SELLER_ID_CONFLICT', collect($network['findings'])->pluck('code'));
        $this->assertSame('shared-identity', $first->seller_id);
        $this->assertSame('shared-identity', $duplicate->seller_id);
    }

    public function test_disabled_and_confidential_identity_conflicts_remain_visible_without_leaking_private_values(): void
    {
        SellerDeclaration::withoutGlobalScopes()->create([
            'organization_id' => $this->publisher->organization_id,
            'publisher_id' => $this->publisher->id,
            'seller_id' => 'disabled-conflict',
            'seller_type' => 'PUBLISHER',
            'name' => 'Publisher One LLC',
            'domain' => 'publisher-one-owner.example',
            'is_confidential' => false,
            'status' => 'DISABLED',
        ]);
        SellerDeclaration::withoutGlobalScopes()->create([
            'organization_id' => $this->publisher->organization_id,
            'publisher_id' => $this->publisher->id,
            'site_id' => $this->site->id,
            'seller_id' => 'disabled-conflict',
            'seller_type' => 'INTERMEDIARY',
            'name' => 'Conflicting Draft Name',
            'domain' => 'publisher-one-owner.example',
            'is_confidential' => true,
            'status' => 'DISABLED',
        ]);
        $firstConfidential = $this->seller('confidential-conflict', 'PUBLISHER', true);
        SellerDeclaration::withoutGlobalScopes()->create([
            'organization_id' => $this->publisher->organization_id,
            'publisher_id' => $this->publisher->id,
            'site_id' => $this->site->id,
            'seller_id' => 'confidential-conflict',
            'seller_type' => 'PUBLISHER',
            'name' => 'Different Private Legal Name',
            'domain' => 'publisher-one-owner.example',
            'is_confidential' => true,
            'status' => 'ACTIVE',
        ]);

        $overview = app(SupplyChainComplianceService::class)->networkOverview();
        $conflicts = collect($overview['findings'])->where('code', 'SELLER_ID_CONFLICT');
        $artifact = app(SupplyChainArtifactBuilder::class)->sellersJson();

        $this->assertContains('disabled-conflict', $conflicts->pluck('seller_id'));
        $this->assertContains('confidential-conflict', $conflicts->pluck('seller_id'));
        $this->assertStringNotContainsString('Different Private Legal Name', $artifact);
        $this->assertStringNotContainsString('confidential-conflict', $artifact);
        $this->assertSame('confidential-conflict', $firstConfidential->seller_id);
    }

    public function test_static_config_contains_only_valid_runtime_schain_and_omits_control_plane_identity_data(): void
    {
        $seller = $this->seller('runtime-42', 'BOTH');
        $config = app(SiteConfigurationBuilder::class)->build($this->site, ConfigEnvironment::Production, 1);
        $schain = data_get($config, 'supplyChain.schain');

        $this->assertSame([], app(SupplyChainObjectValidator::class)->validate($schain));
        $this->assertSame(['complete', 'ver', 'nodes'], array_keys($schain));
        $this->assertSame(['asi', 'sid', 'hp'], array_keys($schain['nodes'][0]));
        $this->assertArrayNotHasKey('adsTxtUrl', $config['supplyChain']);
        $this->assertArrayNotHasKey('sellersJsonUrl', $config['supplyChain']);
        $encoded = json_encode($config, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($seller->id, $encoded);
        $this->assertStringNotContainsString('Publisher One LLC', $encoded);
        $this->assertStringNotContainsString('publisher-one-owner.example', $encoded);

        app(SellerDeclarationManager::class)->deactivate($seller, $this->admin);
        $withoutSeller = app(SiteConfigurationBuilder::class)->build($this->site->refresh(), ConfigEnvironment::Production, 2);
        $this->assertArrayNotHasKey('supplyChain', $withoutSeller);
    }

    public function test_artifact_and_site_runtime_builds_are_deterministic_for_unchanged_truth(): void
    {
        $this->seller('deterministic-42', 'PUBLISHER');
        $builder = app(SupplyChainArtifactBuilder::class);
        $first = $builder->files();
        $second = $builder->files();

        $this->assertSame($first, $second);
        $this->assertSame(hash('sha256', $first['sellers.json']), hash('sha256', $second['sellers.json']));
        $this->assertSame(
            data_get(app(SiteConfigurationBuilder::class)->build($this->site, ConfigEnvironment::Production, 1), 'supplyChain'),
            data_get(app(SiteConfigurationBuilder::class)->build($this->site, ConfigEnvironment::Production, 1), 'supplyChain'),
        );
    }

    private function seller(string $sellerId, string $type, bool $confidential = false): SellerDeclaration
    {
        return $this->sellerFor($this->publisher, $sellerId, $type, $confidential);
    }

    private function sellerFor($publisher, string $sellerId, string $type, bool $confidential = false): SellerDeclaration
    {
        $manager = app(SellerDeclarationManager::class);
        $seller = $manager->create([
            'publisher_id' => $publisher->id,
            'site_id' => null,
            'seller_id' => $sellerId,
            'seller_type' => $type,
            'name' => $publisher->legal_name,
            'domain' => $publisher->business_domain,
            'is_confidential' => $confidential,
        ], $this->admin);
        $manager->review($seller, SupplyChainReviewStatus::Verified, $this->admin);

        return $manager->activate($seller->fresh(), $this->admin);
    }
}
