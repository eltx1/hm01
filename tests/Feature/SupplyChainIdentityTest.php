<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SupplyChainReviewStatus;
use App\Models\DemandAccount;
use App\Models\DemandAdsTxtRecord;
use App\Models\DemandNetwork;
use App\Models\DemandSite;
use App\Models\SellerDeclaration;
use App\Models\Site;
use App\Models\SiteConfig;
use App\Services\Inventory\SiteConfigurationBuilder;
use App\Services\Demand\DemandReportService;
use App\Services\SupplyChain\DomainNormalizer;
use App\Services\SupplyChain\SupplyChainArtifactBuilder;
use App\Services\SupplyChain\SupplyChainIdentityBackfill;
use App\Services\SupplyChain\SupplyChainInvariantService;
use Database\Seeders\DemandNetworkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class SupplyChainIdentityTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_owner_domain_is_publisher_business_domain_for_multiple_different_website_domains(): void
    {
        [$publisher, $site, $account] = $this->context('owner-company.example', 'news-brand.example');
        $second = $this->makeSiteFor($publisher, $this->publisherUser, ['primary_domain' => 'sports-brand.example']);
        $this->map($account, $second);

        $files = app(SupplyChainArtifactBuilder::class)->files();

        $this->assertStringContainsString("OWNERDOMAIN=owner-company.example\n", $files['supply/sites/'.$site->public_key.'/ads.txt']);
        $this->assertStringContainsString("OWNERDOMAIN=owner-company.example\n", $files['supply/sites/'.$second->public_key.'/ads.txt']);
        $this->assertStringNotContainsString('OWNERDOMAIN=news-brand.example', $files['supply/sites/'.$site->public_key.'/ads.txt']);
        $this->assertSame(SupplyChainInvariantService::OWNER_DOMAIN_DECLARED, app(SupplyChainInvariantService::class)->ownerIdentity($site)['source']);
    }

    public function test_legacy_publisher_keeps_artifact_with_explicit_review_required_fallback(): void
    {
        [$publisher, $site] = $this->context(null, 'legacy-news.example');

        $owner = app(SupplyChainInvariantService::class)->ownerIdentity($site);
        $adsTxt = app(SupplyChainArtifactBuilder::class)->files()['supply/sites/'.$site->public_key.'/ads.txt'];

        $this->assertNull($publisher->business_domain);
        $this->assertSame(SupplyChainReviewStatus::ReviewRequired, $publisher->supply_chain_review_status);
        $this->assertSame(SupplyChainInvariantService::OWNER_DOMAIN_LEGACY_FALLBACK, $owner['source']);
        $this->assertSame('OWNER_DOMAIN_REVIEW_REQUIRED', $owner['findings'][0]['code']);
        $this->assertStringContainsString("OWNERDOMAIN=legacy-news.example\n", $adsTxt);
    }

    public function test_admin_business_domain_change_is_normalized_audited_and_resets_review(): void
    {
        [$publisher] = $this->context();
        app(SupplyChainInvariantService::class)->reviewPublisherIdentity(
            $publisher,
            SupplyChainReviewStatus::Verified,
            $this->admin,
        );

        $response = $this->actingAs($this->admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put(route('admin.publishers.update', $publisher), [
            'legal_name' => $publisher->legal_name,
            'display_name' => $publisher->display_name,
            'business_domain' => 'HTTPS://NEW-OWNER.EXAMPLE./',
            'organization_slug' => $publisher->organization->slug,
            'status' => $publisher->status->value,
            'billing_email' => $publisher->billing_email,
            ]);
        $response->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status', 'Publisher updated.');

        $publisher->refresh();
        $this->assertSame('new-owner.example', $publisher->business_domain);
        $this->assertSame(SupplyChainReviewStatus::ReviewRequired, $publisher->supply_chain_review_status);
        $this->assertNull($publisher->supply_chain_reviewed_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'publisher.updated',
            'new_values->business_domain' => 'new-owner.example',
            'new_values->supply_chain_review_status' => 'REVIEW_REQUIRED',
        ]);
    }

    public function test_global_and_site_records_are_scoped_and_exact_duplicates_are_collapsed(): void
    {
        [$publisher, $site, $account] = $this->context();
        $second = $this->makeSiteFor($publisher, $this->publisherUser, ['primary_domain' => 'second-site.example']);
        $this->map($account, $second);
        $global = ['domain' => 'exchange.example', 'publisher_account_id' => 'global-100', 'relationship' => 'DIRECT', 'certification_authority_id' => 'abc123'];
        $this->record($account, null, $global);
        $this->record($account, $site, $global);
        $this->record($account, $site, ['domain' => 'site-demand.example', 'publisher_account_id' => 'site-200', 'relationship' => 'RESELLER']);

        $first = app(SupplyChainInvariantService::class)->adsTxtForSite($site);
        $other = app(SupplyChainInvariantService::class)->adsTxtForSite($second);

        $this->assertCount(2, $first['lines']);
        $this->assertSame(1, collect($first['lines'])->filter(fn (string $line) => str_contains($line, 'global-100'))->count());
        $this->assertContains('DUPLICATE_ADS_TXT_RECORD', collect($first['findings'])->pluck('code'));
        $this->assertSame(['exchange.example, global-100, DIRECT, abc123'], $other['lines']);
    }

    public function test_disabled_record_account_network_and_site_mapping_are_excluded(): void
    {
        [, $site, $account, $mapping] = $this->context();
        $record = $this->record($account, null, ['domain' => 'exchange.example', 'publisher_account_id' => 'seller-1', 'relationship' => 'DIRECT']);
        $this->assertCount(1, app(SupplyChainInvariantService::class)->adsTxtForSite($site)['lines']);

        $record->update(['status' => 'DISABLED']);
        $this->assertSame([], app(SupplyChainInvariantService::class)->adsTxtForSite($site)['lines']);
        $record->update(['status' => 'ACTIVE']);
        $mapping->update(['is_enabled' => false]);
        $this->assertSame([], app(SupplyChainInvariantService::class)->adsTxtForSite($site)['lines']);
        $mapping->update(['is_enabled' => true]);
        $account->update(['is_enabled' => false]);
        $this->assertSame([], app(SupplyChainInvariantService::class)->adsTxtForSite($site)['lines']);
        $account->update(['is_enabled' => true]);
        $account->network->update(['is_enabled' => false]);
        $this->assertSame([], app(SupplyChainInvariantService::class)->adsTxtForSite($site)['lines']);
    }

    public function test_conflicting_ads_txt_relationships_are_detected_and_not_guessed(): void
    {
        [, $site, $account] = $this->context();
        $base = ['domain' => 'exchange.example', 'publisher_account_id' => 'seller-1'];
        $this->record($account, null, $base + ['relationship' => 'DIRECT']);
        $this->record($account, $site, $base + ['relationship' => 'RESELLER']);

        $result = app(SupplyChainInvariantService::class)->adsTxtForSite($site);

        $this->assertSame([], $result['lines']);
        $this->assertContains('ADS_TXT_RELATIONSHIP_CONFLICT', collect($result['findings'])->pluck('code'));
    }

    public function test_connector_sync_normalizes_deduplicates_and_preserves_review_until_identity_changes(): void
    {
        [, , $account, $mapping] = $this->context();
        $account->update(['configuration' => [
            'ads_txt_records' => [
                'Exchange.Example, seller-100, direct, ABC123',
                'exchange.example, seller-100, DIRECT, abc123',
            ],
        ]]);
        $reports = app(DemandReportService::class);

        $this->assertSame(1, $reports->syncAdsTxt($account->fresh(), $mapping->fresh(), $this->admin));
        $record = DemandAdsTxtRecord::withoutGlobalScope('organization')->firstOrFail();
        $this->assertSame('exchange.example, seller-100, DIRECT, abc123', $record->raw_record);
        $this->assertSame(SupplyChainReviewStatus::ReviewRequired, $record->review_status);

        $record->update([
            'review_status' => SupplyChainReviewStatus::Verified,
            'reviewed_at' => now(),
            'reviewed_by' => $this->admin->id,
        ]);
        $reports->syncAdsTxt($account->fresh(), $mapping->fresh(), $this->admin);
        $this->assertSame(SupplyChainReviewStatus::Verified, $record->fresh()->review_status);

        $account->update(['configuration' => ['ads_txt_records' => []]]);
        $this->assertSame(0, $reports->syncAdsTxt($account->fresh(), $mapping->fresh(), $this->admin));
        $this->assertDatabaseHas('demand_ads_txt_records', ['id' => $record->id, 'status' => 'REMOVED']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'demand.ads_txt.synchronized']);
    }

    public function test_confidential_and_duplicate_sellers_emit_one_redacted_entry_and_one_schain_node(): void
    {
        [$publisher, $site] = $this->context();
        $identity = [
            'seller_id' => 'horus-seller-42',
            'seller_type' => 'PUBLISHER',
            'name' => 'Private Publisher LLC',
            'domain' => 'publisher-owner.example',
            'is_confidential' => true,
        ];
        $service = app(SupplyChainInvariantService::class);
        $service->saveSellerDeclaration($publisher, null, $identity, $this->admin);
        $service->saveSellerDeclaration($publisher, $site, $identity + ['name' => 'Alternate internal name'], $this->admin);

        $result = $service->sellers();
        $payload = $result['sellers'][0]['payload'];
        $schain = $service->schainForSite($site);

        $this->assertCount(1, $result['sellers']);
        $this->assertSame(1, $payload['is_confidential']);
        $this->assertNull($payload['name']);
        $this->assertNull($payload['domain']);
        $this->assertContains('DUPLICATE_SELLER_DECLARATION', collect($result['findings'])->pluck('code'));
        $this->assertSame(1, $schain['complete']);
        $this->assertSame([['asi' => 'horusmedia.net', 'sid' => 'horus-seller-42', 'hp' => 1]], $schain['nodes']);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'supply_chain.seller.updated',
            'new_values->name' => '[CONFIDENTIAL]',
            'new_values->domain' => '[CONFIDENTIAL]',
        ]);
    }

    public function test_seller_id_conflicts_are_rejected_on_write_and_detected_in_legacy_data(): void
    {
        [$publisher, $site] = $this->context();
        $service = app(SupplyChainInvariantService::class);
        $service->saveSellerDeclaration($publisher, null, [
            'seller_id' => 'shared-id', 'seller_type' => 'PUBLISHER', 'name' => 'Publisher LLC',
            'domain' => 'publisher-owner.example', 'is_confidential' => false,
        ], $this->admin);
        [$otherPublisher, $otherSite] = $this->publisherAndSite('other-owner.example', 'other-site.example');

        try {
            $service->saveSellerDeclaration($otherPublisher, $otherSite, [
                'seller_id' => 'shared-id', 'seller_type' => 'PUBLISHER', 'name' => 'Other LLC',
                'domain' => 'other-owner.example', 'is_confidential' => false,
            ], $this->admin);
            $this->fail('A conflicting seller ID should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('seller_id', $exception->errors());
        }

        SellerDeclaration::withoutGlobalScope('organization')->create([
            'organization_id' => $otherPublisher->organization_id,
            'publisher_id' => $otherPublisher->id,
            'site_id' => $otherSite->id,
            'seller_id' => 'shared-id',
            'seller_type' => 'PUBLISHER',
            'name' => 'Other LLC',
            'domain' => 'other-owner.example',
            'status' => 'ACTIVE',
        ]);
        $network = $service->sellers();

        $this->assertSame([], $network['sellers']);
        $this->assertContains('SELLER_ID_CONFLICT', collect($network['findings'])->pluck('code'));
        $this->assertSame(0, $service->schainForSite($site)['complete']);
    }

    public function test_disabled_seller_is_absent_from_sellers_json_and_schain(): void
    {
        [$publisher, $site] = $this->context();
        app(SupplyChainInvariantService::class)->saveSellerDeclaration($publisher, null, [
            'seller_id' => 'disabled-id', 'seller_type' => 'PUBLISHER', 'name' => 'Publisher LLC',
            'domain' => 'publisher-owner.example', 'is_confidential' => false, 'status' => 'DISABLED',
        ], $this->admin);
        $files = app(SupplyChainArtifactBuilder::class)->files();
        $sellers = json_decode($files['supply/sellers.json'], true, 512, JSON_THROW_ON_ERROR);
        $schain = app(SupplyChainInvariantService::class)->schainForSite($site);

        $this->assertSame([], $sellers['sellers']);
        $this->assertSame(0, $schain['complete']);
        $this->assertSame([], $schain['nodes']);
    }

    public function test_intermediary_seller_emits_known_node_but_does_not_claim_complete_chain(): void
    {
        [$publisher, $site] = $this->context();
        app(SupplyChainInvariantService::class)->saveSellerDeclaration($publisher, null, [
            'seller_id' => 'intermediary-id', 'seller_type' => 'INTERMEDIARY', 'name' => 'Publisher Network LLC',
            'domain' => 'publisher-owner.example', 'is_confidential' => false,
        ], $this->admin);

        $schain = app(SupplyChainInvariantService::class)->schainForSite($site);

        $this->assertSame(0, $schain['complete']);
        $this->assertSame([['asi' => 'horusmedia.net', 'sid' => 'intermediary-id', 'hp' => 1]], $schain['nodes']);
        $this->assertContains('SCHAIN_UPSTREAM_IDENTITY_REQUIRED', collect($schain['findings'])->pluck('code'));
    }

    public function test_artifacts_and_static_schain_are_deterministic_and_idempotent(): void
    {
        [$publisher, $site, $account] = $this->context();
        $this->record($account, null, ['domain' => 'z-exchange.example', 'publisher_account_id' => '200', 'relationship' => 'RESELLER']);
        $this->record($account, $site, ['domain' => 'a-exchange.example', 'publisher_account_id' => '100', 'relationship' => 'DIRECT']);
        app(SupplyChainInvariantService::class)->saveSellerDeclaration($publisher, null, [
            'seller_id' => 'publisher-sid', 'seller_type' => 'BOTH', 'name' => 'Publisher LLC',
            'domain' => 'publisher-owner.example', 'is_confidential' => false,
        ], $this->admin);
        SiteConfig::withoutGlobalScope('organization')->updateOrCreate(
            ['site_id' => $site->id],
            [
                'organization_id' => $site->organization_id,
                'supply_chain_settings' => [
                    'schain' => [
                        'complete' => 1,
                        'ver' => '9.9',
                        'nodes' => [['asi' => 'forged.example', 'sid' => 'forged', 'hp' => 0]],
                    ],
                ],
            ],
        );

        $first = app(SupplyChainArtifactBuilder::class)->files();
        $second = app(SupplyChainArtifactBuilder::class)->files();
        $config = app(SiteConfigurationBuilder::class)->build($site, ConfigEnvironment::Production, 1);

        $this->assertSame($first, $second);
        $this->assertLessThan(
            strpos($first['supply/sites/'.$site->public_key.'/ads.txt'], 'z-exchange.example'),
            strpos($first['supply/sites/'.$site->public_key.'/ads.txt'], 'a-exchange.example'),
        );
        $this->assertSame(['asi' => 'horusmedia.net', 'sid' => 'publisher-sid', 'hp' => 1], data_get($config, 'supplyChain.schain.nodes.0'));
        $this->assertSame(1, data_get($config, 'supplyChain.schain.complete'));
    }

    public function test_cross_organization_site_and_publisher_mismatches_are_rejected_or_excluded(): void
    {
        [$publisher, , $account] = $this->context();
        [$otherPublisher, $otherSite] = $this->publisherAndSite('other-owner.example', 'other-site.example');
        $account->update(['scope' => DemandAccountScope::Publisher, 'publisher_id' => $publisher->id]);
        $this->map($account, $otherSite);
        $this->recordDirectly($account, $otherSite, ['domain' => 'exchange.example', 'publisher_account_id' => 'wrong-org', 'relationship' => 'DIRECT']);

        $adsTxt = app(SupplyChainInvariantService::class)->adsTxtForSite($otherSite);
        $this->assertSame([], $adsTxt['lines']);
        $this->assertContains('DEMAND_ACCOUNT_PUBLISHER_MISMATCH', collect($adsTxt['findings'])->pluck('code'));

        $this->expectException(ValidationException::class);
        app(SupplyChainInvariantService::class)->saveSellerDeclaration($publisher, $otherSite, [
            'seller_id' => 'cross-org', 'seller_type' => 'PUBLISHER', 'name' => 'Publisher LLC',
            'domain' => 'publisher-owner.example', 'is_confidential' => false,
        ], $this->admin);
    }

    public function test_migration_backfills_only_structural_seller_ownership_and_never_guesses_business_domain(): void
    {
        [$publisher, $site] = $this->context(null);
        $seller = SellerDeclaration::withoutGlobalScope('organization')->create([
            'organization_id' => $publisher->organization_id,
            'publisher_id' => null,
            'site_id' => $site->id,
            'seller_id' => 'legacy-seller',
            'seller_type' => 'PUBLISHER',
            'name' => 'Legacy Publisher',
            'domain' => 'legacy.example',
            'is_confidential' => false,
            'status' => 'ACTIVE',
        ]);
        $globalSeller = SellerDeclaration::withoutGlobalScope('organization')->create([
            'organization_id' => $publisher->organization_id,
            'publisher_id' => null,
            'site_id' => null,
            'seller_id' => 'legacy-global-seller',
            'seller_type' => 'PUBLISHER',
            'name' => 'Legacy Publisher',
            'domain' => 'legacy.example',
            'status' => 'ACTIVE',
        ]);

        $this->assertSame(2, app(SupplyChainIdentityBackfill::class)->run());
        $this->assertSame(0, app(SupplyChainIdentityBackfill::class)->run());

        $this->assertDatabaseHas('seller_declarations', [
            'id' => $seller->id,
            'publisher_id' => $publisher->id,
            'review_status' => 'REVIEW_REQUIRED',
        ]);
        $this->assertDatabaseHas('seller_declarations', [
            'id' => $globalSeller->id,
            'publisher_id' => $publisher->id,
            'review_status' => 'REVIEW_REQUIRED',
        ]);
        $this->assertDatabaseHas('publishers', [
            'id' => $publisher->id,
            'business_domain' => null,
            'supply_chain_review_status' => 'REVIEW_REQUIRED',
        ]);
    }

    public function test_domain_normalization_is_strict_and_safe_for_comparisons(): void
    {
        $normalizer = app(DomainNormalizer::class);

        $this->assertSame('publisher.example', $normalizer->normalize('HTTPS://Publisher.Example./'));
        $this->assertTrue($normalizer->same('publisher.example', 'https://PUBLISHER.EXAMPLE/'));

        $this->expectException(InvalidArgumentException::class);
        $normalizer->normalize('https://publisher.example/private/path');
    }

    private $admin;

    private $publisherUser;

    private function context(?string $businessDomain = 'publisher-owner.example', string $siteDomain = 'publisher-site.example'): array
    {
        $this->seedIdentity();
        $this->seed(DemandNetworkSeeder::class);
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($horus, RoleName::SuperAdmin);
        [$publisher, $site] = $this->publisherAndSite($businessDomain, $siteDomain);
        $network = DemandNetwork::query()->where('code', 'MGID')->firstOrFail();
        $account = DemandAccount::withoutGlobalScope('organization')->create([
            'organization_id' => $horus->id,
            'demand_network_id' => $network->id,
            'name' => 'Horus demand',
            'scope' => DemandAccountScope::HorusMedia,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => true,
            'revenue_share_percent' => 20,
            'fallback_priority' => 10,
        ]);
        $mapping = $this->map($account, $site);

        return [$publisher, $site, $account, $mapping];
    }

    private function publisherAndSite(?string $businessDomain, string $siteDomain): array
    {
        $organization = $this->makeOrganization(OrganizationType::Publisher);
        $this->publisherUser = $this->makeUser($organization, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($this->publisherUser, ['business_domain' => $businessDomain]);
        $site = $this->makeSiteFor($publisher, $this->publisherUser, ['primary_domain' => $siteDomain]);

        return [$publisher, $site];
    }

    private function map(DemandAccount $account, Site $site): DemandSite
    {
        return DemandSite::withoutGlobalScope('organization')->updateOrCreate(
            ['demand_account_id' => $account->id, 'site_id' => $site->id],
            [
                'organization_id' => $site->organization_id,
                'approval_status' => DemandApprovalStatus::Approved,
                'is_enabled' => true,
                'is_default' => true,
                'integration_mode' => DemandIntegrationMode::DirectJs,
            ],
        );
    }

    private function record(DemandAccount $account, ?Site $site, array $attributes): DemandAdsTxtRecord
    {
        $normalized = app(SupplyChainInvariantService::class)->normalizeDemandRecord($account, $site, $attributes);

        return DemandAdsTxtRecord::withoutGlobalScope('organization')->create(array_merge($normalized, [
            'demand_account_id' => $account->id,
            'site_id' => $site?->id,
            'status' => 'ACTIVE',
            'source' => 'TEST',
            'last_verified_at' => now(),
        ]));
    }

    private function recordDirectly(DemandAccount $account, Site $site, array $attributes): DemandAdsTxtRecord
    {
        $line = implode(', ', array_filter([
            $attributes['domain'], $attributes['publisher_account_id'], $attributes['relationship'], $attributes['certification_authority_id'] ?? null,
        ]));

        return DemandAdsTxtRecord::withoutGlobalScope('organization')->create(array_merge($attributes, [
            'organization_id' => $account->organization_id,
            'demand_account_id' => $account->id,
            'site_id' => $site->id,
            'record_hash' => hash('sha256', $line),
            'raw_record' => $line,
            'status' => 'ACTIVE',
            'source' => 'LEGACY',
        ]));
    }
}
