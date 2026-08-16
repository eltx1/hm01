<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SiteManagementRole;
use App\Enums\SupplyChainReviewStatus;
use App\Models\SellerDeclaration;
use App\Services\Compliance\SupplyChainComplianceService;
use App\Services\SupplyChain\SupplyChainArtifactBuilder;
use App\Services\SupplyChain\SupplyChainCrossConsistencyValidator;
use App\Services\SupplyChain\SupplyChainInvariantService;
use App\Services\SupplyChain\SupplyChainStandardsContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class SupplyChainCrossConsistencyTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;
    private $publisherUser;
    private $publisher;
    private $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Cross Consistency Publisher');
        $this->publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser, [
            'legal_name' => 'Cross Consistency Publisher LLC',
            'display_name' => 'Cross Consistency Publisher',
            'business_domain' => 'cross-consistency.example',
        ]);
        $this->site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Cross Consistency News',
            'primary_domain' => 'news.cross-consistency.example',
        ]);
    }

    public function test_reviewed_publisher_uses_one_canonical_seller_across_all_public_artifacts(): void
    {
        [$seller, $network, $adsTxt, $schain] = $this->validCanonicalState();
        $this->site->servingSettings()->firstOrFail()->update([
            'monetization_manager_role' => SiteManagementRole::HorusPrimaryGlobal,
        ]);

        $result = app(SupplyChainCrossConsistencyValidator::class)->validateSite($this->site, $network, $adsTxt, $schain);
        $artifact = app(SupplyChainArtifactBuilder::class)->adsTxtForSite($this->site, $adsTxt);
        $payload = app(SupplyChainArtifactBuilder::class)->sellersJsonPayload();

        $this->assertTrue($result['compliant'], json_encode($result['findings'], JSON_PRETTY_PRINT));
        $this->assertSame($seller->seller_id, data_get($payload, 'sellers.0.seller_id'));
        $this->assertSame('PUBLISHER', data_get($payload, 'sellers.0.seller_type'));
        $this->assertSame('cross-consistency.example', data_get($payload, 'sellers.0.domain'));
        $this->assertContains('horusmedia.net, '.$seller->seller_id.', DIRECT', $adsTxt['lines']);
        $this->assertSame($seller->seller_id, data_get($schain, 'nodes.0.sid'));
        $this->assertSame(1, $schain['complete']);
        $this->assertStringContainsString('OWNERDOMAIN=cross-consistency.example', $artifact);
        $this->assertStringContainsString('MANAGERDOMAIN=horusmedia.net', $artifact);
        $this->assertStringNotContainsString((string) $this->site->public_key, json_encode([$payload, $adsTxt, $schain], JSON_THROW_ON_ERROR));
    }

    public function test_country_scoped_managerdomain_requires_explicit_country_role(): void
    {
        $settings = $this->site->servingSettings()->firstOrFail();
        $settings->update([
            'monetization_manager_role' => SiteManagementRole::HorusPrimaryCountry,
            'monetization_manager_country' => 'EG',
        ]);

        $directive = app(SupplyChainStandardsContract::class)->managerDirectiveForSite($this->site);

        $this->assertSame('HORUS_PRIMARY_COUNTRY', $directive['role']);
        $this->assertSame('PRIMARY', $directive['relationship']);
        $this->assertSame('MANAGERDOMAIN=horusmedia.net, EG', $directive['line']);
    }

    public function test_legacy_manager_fields_without_authorized_role_fail_admin_readiness(): void
    {
        $this->validCanonicalState();
        $this->site->servingSettings()->firstOrFail()->update([
            'monetization_manager_role' => SiteManagementRole::None,
            'monetization_manager_domain' => 'horusmedia.net',
            'monetization_manager_relationship' => 'PRIMARY',
        ]);

        $summary = app(SupplyChainComplianceService::class)->siteOverview($this->site);

        $this->assertSame('CONFLICT', $summary['cross_consistency_status']);
        $this->assertContains('MANAGERDOMAIN_NOT_AUTHORIZED', collect($summary['findings'])->pluck('code'));
    }

    public function test_hostile_inconsistent_artifacts_report_required_cross_consistency_codes(): void
    {
        [$seller, $network, $adsTxt, $schain] = $this->validCanonicalState();
        $validator = app(SupplyChainCrossConsistencyValidator::class);
        $sellerId = (string) $seller->seller_id;

        $duplicateNetwork = $network;
        $duplicateNetwork['sellers'][] = $network['sellers'][0];
        $duplicate = $validator->validateSite($this->site, $duplicateNetwork, $adsTxt, $schain);
        $this->assertContains('HORUS_SELLER_DUPLICATED', collect($duplicate['findings'])->pluck('code'));

        $missing = $validator->validateSite(
            $this->site,
            ['sellers' => [], 'findings' => []],
            ['lines' => ['horusmedia.net, missing-seller, DIRECT'], 'findings' => []],
            ['complete' => 0, 'ver' => '1.0', 'nodes' => [['asi' => 'horusmedia.net', 'sid' => 'missing-seller', 'hp' => 1]], 'findings' => []],
        );
        $this->assertContains('HORUS_SELLER_MISSING_FROM_SELLERS_JSON', collect($missing['findings'])->pluck('code'));

        $wrongAds = $validator->validateSite(
            $this->site,
            $network,
            ['lines' => ['horusmedia.net, wrong-seller, DIRECT'], 'findings' => []],
            $schain,
        );
        $this->assertContains('HORUS_ADS_TXT_SELLER_MISMATCH', collect($wrongAds['findings'])->pluck('code'));

        $wrongDomainNetwork = $network;
        $wrongDomainNetwork['sellers'][0]['payload']['domain'] = 'wrong-owner.example';
        $wrongDomain = $validator->validateSite($this->site, $wrongDomainNetwork, $adsTxt, $schain);
        $this->assertContains('OWNERDOMAIN_SELLER_DOMAIN_MISMATCH', collect($wrongDomain['findings'])->pluck('code'));

        $wrongChain = $validator->validateSite(
            $this->site,
            $network,
            $adsTxt,
            ['complete' => 0, 'ver' => '1.0', 'nodes' => [['asi' => 'horusmedia.net', 'sid' => 'wrong-seller', 'hp' => 1]], 'findings' => []],
        );
        $wrongChainCodes = collect($wrongChain['findings'])->pluck('code');
        $this->assertContains('SCHAIN_SELLER_MISMATCH', $wrongChainCodes);
        $this->assertContains('SCHAIN_INCOMPLETE', $wrongChainCodes);

        $relationshipConflict = $validator->validateSite(
            $this->site,
            $network,
            [
                'lines' => [
                    'horusmedia.net, '.$sellerId.', DIRECT',
                    'horusmedia.net, '.$sellerId.', RESELLER',
                ],
                'findings' => [],
            ],
            $schain,
        );
        $this->assertContains('ADS_TXT_RELATIONSHIP_CONFLICT', collect($relationshipConflict['findings'])->pluck('code'));
    }

    public function test_intermediary_chain_stays_incomplete_instead_of_being_promoted_for_readiness(): void
    {
        app(SupplyChainInvariantService::class)->reviewPublisherIdentity($this->publisher, SupplyChainReviewStatus::Verified, $this->admin);
        $seller = $this->globalSeller('intermediary-100', 'DIRECT', 'INTERMEDIARY');
        app(SupplyChainInvariantService::class)->reviewSellerDeclaration($seller, SupplyChainReviewStatus::Verified, $this->admin);
        $contract = app(SupplyChainStandardsContract::class);
        $network = $contract->sellers();
        $adsTxt = $contract->adsTxtForSite($this->site, $network);
        $schain = $contract->schainForSite($this->site, $network);

        $result = app(SupplyChainCrossConsistencyValidator::class)->validateSite($this->site, $network, $adsTxt, $schain);

        $this->assertSame(0, $schain['complete']);
        $this->assertFalse($result['compliant']);
        $this->assertContains('SCHAIN_INCOMPLETE', collect($result['findings'])->pluck('code'));
    }

    /** @return array{SellerDeclaration, array<string, mixed>, array<string, mixed>, array<string, mixed>} */
    private function validCanonicalState(): array
    {
        $invariants = app(SupplyChainInvariantService::class);
        $this->publisher = $invariants->reviewPublisherIdentity($this->publisher, SupplyChainReviewStatus::Verified, $this->admin);
        $seller = $this->globalSeller('canonical-seller-100', 'DIRECT');
        $seller = $invariants->reviewSellerDeclaration($seller, SupplyChainReviewStatus::Verified, $this->admin);
        $contract = app(SupplyChainStandardsContract::class);
        $network = $contract->sellers();
        $adsTxt = $contract->adsTxtForSite($this->site, $network);
        $schain = $contract->schainForSite($this->site, $network);

        return [$seller, $network, $adsTxt, $schain];
    }

    private function globalSeller(string $sellerId, ?string $relationship, string $sellerType = 'PUBLISHER'): SellerDeclaration
    {
        $seller = app(SupplyChainInvariantService::class)->saveSellerDeclaration($this->publisher, null, [
            'seller_id' => $sellerId,
            'seller_type' => $sellerType,
            'name' => 'Cross Consistency Publisher LLC',
            'domain' => 'cross-consistency.example',
            'is_confidential' => false,
        ], $this->admin);
        $seller->forceFill(['ads_txt_relationship' => $relationship])->save();

        return $seller->refresh();
    }
}
