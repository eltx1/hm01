<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\PrebidDeliveryMode;
use App\Enums\RoleName;
use App\Models\SellerDeclaration;
use App\Services\Prebid\PrebidConfigurationBuilder;
use App\Services\SupplyChain\SellersJsonValidator;
use App\Services\SupplyChain\SupplyChainArtifactBuilder;
use App\Services\SupplyChain\SupplyChainInvariantService;
use App\Services\SupplyChain\SupplyChainObjectValidator;
use App\Services\SupplyChain\SupplyChainStandardsContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class SupplyChainStandardsContractTest extends TestCase
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
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Canonical Publisher');
        $this->publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser, [
            'legal_name' => 'Canonical Publisher LLC',
            'display_name' => 'Canonical Publisher',
            'business_domain' => 'canonical-publisher.example',
        ]);
        $this->site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Canonical News',
            'primary_domain' => 'news.canonical-publisher.example',
        ]);
    }

    public function test_one_publisher_multiple_sites_share_one_seller_and_internal_ids_never_become_seller_ids(): void
    {
        $second = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Canonical Sports',
            'primary_domain' => 'sports.canonical-publisher.example',
        ]);
        $seller = $this->globalSeller('legal-entity-100', 'DIRECT');
        $contract = app(SupplyChainStandardsContract::class);

        $firstChain = $contract->schainForSite($this->site);
        $secondChain = $contract->schainForSite($second);

        $this->assertSame('legal-entity-100', $firstChain['nodes'][0]['sid']);
        $this->assertSame('legal-entity-100', $secondChain['nodes'][0]['sid']);
        $this->assertNotSame((string) $this->publisherUser->id, $seller->seller_id);
        $this->assertNotSame((string) $this->site->id, $seller->seller_id);
        $this->assertNotSame((string) $this->site->public_key, $seller->seller_id);
        $this->assertNotSame((string) $second->public_key, $secondChain['nodes'][0]['sid']);
    }

    public function test_seller_type_never_infers_ads_txt_relationship(): void
    {
        $seller = $this->globalSeller('relationship-100', null, 'PUBLISHER');
        $contract = app(SupplyChainStandardsContract::class);
        $withoutRelationship = $contract->adsTxtForSite($this->site);

        $this->assertNotContains('horusmedia.net, relationship-100, DIRECT', $withoutRelationship['lines']);
        $this->assertContains('ADS_TXT_RELATIONSHIP_UNCONFIGURED', collect($withoutRelationship['findings'])->pluck('code'));

        $seller->forceFill(['ads_txt_relationship' => 'RESELLER'])->save();
        $withRelationship = $contract->adsTxtForSite($this->site);
        $this->assertContains('horusmedia.net, relationship-100, RESELLER', $withRelationship['lines']);
        $this->assertNotContains('horusmedia.net, relationship-100, DIRECT', $withRelationship['lines']);
    }

    public function test_managerdomain_is_absent_without_explicit_primary_or_exclusive_manager_contract(): void
    {
        $this->globalSeller('manager-100', 'DIRECT');
        $artifact = app(SupplyChainArtifactBuilder::class)->adsTxtForSite($this->site);

        $this->assertStringContainsString('OWNERDOMAIN=canonical-publisher.example', $artifact);
        $this->assertStringNotContainsString('MANAGERDOMAIN=', $artifact);
    }

    public function test_site_scoped_distinct_seller_id_fails_closed_without_publisher_level_legal_entity_identity(): void
    {
        app(SupplyChainInvariantService::class)->saveSellerDeclaration($this->publisher, $this->site, [
            'seller_id' => 'site-derived-100',
            'seller_type' => 'PUBLISHER',
            'name' => 'Canonical Publisher LLC',
            'domain' => 'canonical-publisher.example',
            'is_confidential' => false,
        ], $this->admin)->forceFill(['ads_txt_relationship' => 'DIRECT'])->save();

        $network = app(SupplyChainStandardsContract::class)->sellers();
        $this->assertSame([], $network['sellers']);
        $this->assertContains('SITE_SPECIFIC_SELLER_ID_UNSUPPORTED', collect($network['findings'])->pluck('code'));
        $this->assertStringNotContainsString('site-derived-100', app(SupplyChainArtifactBuilder::class)->sellersJson());
    }

    public function test_prebid_global_ortb2_never_uses_site_public_key_as_publisher_id(): void
    {
        $config = app(PrebidConfigurationBuilder::class)->build($this->site, null, PrebidDeliveryMode::GamBridge);
        $publisher = data_get($config, 'auction.ortb2.site.publisher', []);

        $this->assertArrayNotHasKey('id', $publisher);
        $this->assertSame('Canonical Publisher', $publisher['name']);
        $this->assertSame('canonical-publisher.example', $publisher['domain']);
        $this->assertSame('news.canonical-publisher.example', data_get($config, 'auction.ortb2.site.domain'));
        $this->assertStringNotContainsString((string) $this->site->public_key, json_encode(data_get($config, 'auction.ortb2.site'), JSON_THROW_ON_ERROR));
    }

    public function test_sellers_json_current_optional_fields_validate_and_malformed_public_extensions_fail_closed(): void
    {
        $validator = app(SellersJsonValidator::class);
        $valid = [
            'version' => '1.0',
            'identifiers' => [['name' => 'TAG-ID', 'value' => 'example-tag']],
            'contact_email' => 'supply@example.com',
            'contact_address' => 'Cairo, Egypt',
            'ext' => ['vendor' => ['public_flag' => true]],
            'sellers' => [[
                'seller_id' => 'seller-100',
                'seller_type' => 'BOTH',
                'is_confidential' => 0,
                'is_passthrough' => 1,
                'name' => 'Canonical Publisher LLC',
                'domain' => 'canonical-publisher.example',
                'comment' => 'Public standards metadata.',
                'ext' => ['vendor' => ['public_code' => 'ok']],
            ]],
        ];

        $this->assertSame([], $validator->validate($valid));
        $malformed = $valid;
        $malformed['sellers'][0]['is_passthrough'] = '1';
        $malformed['sellers'][0]['ext'] = ['api_key' => 'must-not-leak'];
        $malformed['sellers'][0]['seller_id'] = "bad seller";
        $this->assertNotSame([], $validator->validate($malformed));
    }

    public function test_supplychain_current_optional_fields_validate_while_invalid_ids_and_hp_fail(): void
    {
        $validator = app(SupplyChainObjectValidator::class);
        $valid = [
            'ver' => '1.0',
            'complete' => 1,
            'nodes' => [[
                'asi' => 'horusmedia.net',
                'sid' => 'seller-100',
                'hp' => 1,
                'rid' => 'request-100',
                'name' => 'Canonical Publisher LLC',
                'domain' => 'canonical-publisher.example',
                'ext' => ['vendor' => ['public_code' => 'ok']],
            ]],
            'ext' => ['vendor' => ['public_flag' => true]],
        ];

        $this->assertSame([], $validator->validate($valid));
        $invalid = $valid;
        $invalid['nodes'][0]['sid'] = str_repeat('x', 65);
        $invalid['nodes'][0]['hp'] = 0;
        $this->assertNotSame([], $validator->validate($invalid));
    }

    public function test_existing_valid_ads_txt_record_shape_remains_accepted(): void
    {
        $parsed = app(\App\Services\Compliance\AdsTxtParser::class)->parse(
            "ssp.example, publisher-42, DIRECT, cert-authority\nreseller.example, reseller-42, RESELLER\n",
        );

        $this->assertCount(2, $parsed['records']);
        $this->assertSame([], $parsed['invalid']);
        $this->assertSame(['DIRECT', 'RESELLER'], array_column($parsed['records'], 'relationship'));
    }

    public function test_runtime_static_config_uses_the_same_canonical_seller_contract(): void
    {
        app(SupplyChainInvariantService::class)->saveSellerDeclaration($this->publisher, $this->site, [
            'seller_id' => 'site-runtime-100',
            'seller_type' => 'PUBLISHER',
            'name' => 'Canonical Publisher LLC',
            'domain' => 'canonical-publisher.example',
            'is_confidential' => false,
        ], $this->admin)->forceFill(['ads_txt_relationship' => 'DIRECT'])->save();

        $config = app(\App\Services\Inventory\SiteConfigurationBuilder::class)
            ->build($this->site, \App\Enums\ConfigEnvironment::Production, 1);

        $this->assertArrayNotHasKey('supplyChain', $config);
        $this->assertStringNotContainsString('site-runtime-100', json_encode($config, JSON_THROW_ON_ERROR));
    }

    private function globalSeller(string $sellerId, ?string $relationship, string $sellerType = 'PUBLISHER'): SellerDeclaration
    {
        $seller = app(SupplyChainInvariantService::class)->saveSellerDeclaration($this->publisher, null, [
            'seller_id' => $sellerId,
            'seller_type' => $sellerType,
            'name' => 'Canonical Publisher LLC',
            'domain' => 'canonical-publisher.example',
            'is_confidential' => false,
        ], $this->admin);
        $seller->forceFill(['ads_txt_relationship' => $relationship])->save();

        return $seller->refresh();
    }
}
