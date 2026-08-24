<?php

namespace Tests\Feature;

use App\Enums\AdsTxtComplianceStatus;
use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SiteStatus;
use App\Enums\SupplyChainReviewStatus;
use App\Models\AuditLog;
use App\Models\DemandAccount;
use App\Models\DemandAdsTxtRecord;
use App\Models\DemandNetwork;
use App\Models\DemandSite;
use App\Models\PlatformAdsTxtRecord;
use App\Models\PrebidBidder;
use App\Models\StaticGlobalArtifactChange;
use App\Services\Compliance\AdsTxtComplianceService;
use App\Services\Compliance\AdsTxtRecordManager;
use App\Services\Network\Contracts\DnsResolver;
use App\Services\Prebid\BidderAdsTxtService;
use App\Services\Prebid\PrebidManager;
use App\Services\SupplyChain\AdsTxtBulkParser;
use App\Services\SupplyChain\PlatformAdsTxtService;
use App\Services\SupplyChain\SupplyChainArtifactBuilder;
use App\Services\SupplyChain\SupplyChainStandardsContract;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\PrebidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class PlatformMasterAdsTxtTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;
    private $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed([DemandNetworkSeeder::class, PrebidSeeder::class]);
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher, 'Publisher A'), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, ['business_domain' => 'publisher-a.com']);
        $this->site = $this->makeSiteFor($publisher, $publisherUser, ['primary_domain' => 'site-a.com']);
        $this->site->update(['status' => SiteStatus::Active]);
        $this->publicDns();
    }

    public function test_one_master_record_appears_on_all_eligible_sites_and_disabled_disappears(): void
    {
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher, 'Publisher B'), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, ['business_domain' => 'publisher-b.com']);
        $siteB = $this->makeSiteFor($publisher, $publisherUser, ['primary_domain' => 'site-b.com']);
        $siteB->update(['status' => SiteStatus::Approved]);

        $master = $this->master('master.exchange.com', 'global-seat', 'RESELLER', 'ca-master');
        $contract = app(SupplyChainStandardsContract::class);
        $line = 'master.exchange.com, global-seat, RESELLER, ca-master';

        $this->assertContains($line, $contract->adsTxtForSite($this->site)['lines']);
        $this->assertContains($line, $contract->adsTxtForSite($siteB)['lines']);

        app(PlatformAdsTxtService::class)->disable($master, $this->admin);
        $this->assertNotContains($line, $contract->adsTxtForSite($this->site)['lines']);
        $this->assertNotContains($line, $contract->adsTxtForSite($siteB)['lines']);
    }

    public function test_effective_dates_gate_master_record(): void
    {
        $service = app(PlatformAdsTxtService::class);
        $future = $service->create([
            'advertising_system_domain' => 'future.exchange.com',
            'publisher_account_id' => 'future-seat',
            'relationship' => 'DIRECT',
            'effective_from' => now()->addDay(),
        ], $this->admin);
        $service->review($future, SupplyChainReviewStatus::Verified, $this->admin);
        $service->enable($future, $this->admin);
        $this->assertNotContains($future->raw_record, app(SupplyChainStandardsContract::class)->adsTxtForSite($this->site)['lines']);

        $future->update(['effective_from' => now()->subMinute(), 'effective_to' => now()->addMinute()]);
        $this->assertContains($future->raw_record, app(SupplyChainStandardsContract::class)->adsTxtForSite($this->site)['lines']);

        $future->update(['effective_to' => now()->subSecond()]);
        $this->assertNotContains($future->raw_record, app(SupplyChainStandardsContract::class)->adsTxtForSite($this->site)['lines']);
    }

    public function test_exact_duplicate_across_master_bidder_and_demand_collapses_and_preserves_provenance(): void
    {
        $master = $this->master('shared.exchange.com', 'seller-100', 'DIRECT', 'abc123');
        $this->bidderRecord('shared.exchange.com', 'seller-100', 'DIRECT', 'abc123');
        $this->demandRecord('shared.exchange.com', 'seller-100', 'DIRECT', 'abc123');

        $result = app(SupplyChainStandardsContract::class)->adsTxtForSite($this->site);
        $this->assertSame(1, collect($result['lines'])->where(fn ($line) => $line === $master->raw_record)->count());
        $entry = collect($result['entries'])->firstWhere('line', $master->raw_record);
        $types = collect($entry['provenance'])->pluck('source_type')->sort()->values()->all();
        $this->assertSame(['BIDDER_RECORD', 'DEMAND_RECORD', 'PLATFORM_MASTER'], $types);
        $this->assertTrue(collect($result['findings'])->contains(fn ($finding) => ($finding['code'] ?? null) === 'DUPLICATE_ADS_TXT_RECORD'));
    }

    public function test_conflicting_duplicate_blocks_compliance_without_silent_preference(): void
    {
        $master = $this->master('conflict.exchange.com', 'seller-x', 'RESELLER', null);
        $this->demandRecord('conflict.exchange.com', 'seller-x', 'DIRECT', null);

        $result = app(SupplyChainStandardsContract::class)->adsTxtForSite($this->site);
        $this->assertNotContains($master->raw_record, $result['lines']);
        $this->assertTrue(collect($result['findings'])->contains(fn ($finding) => ($finding['code'] ?? null) === 'ADS_TXT_RELATIONSHIP_CONFLICT'));
        $this->assertSame(AdsTxtComplianceStatus::Conflict->value, app(AdsTxtComplianceService::class)->summary($this->site)['status']);
    }

    public function test_tenant_specific_sources_do_not_leak_while_master_remains_platform_global(): void
    {
        $master = $this->master('master.exchange.com', 'all-sites', 'RESELLER', null);
        $demand = $this->demandRecord('tenant.exchange.com', 'tenant-a', 'DIRECT', null);

        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher, 'Publisher B'), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, ['business_domain' => 'publisher-b.com']);
        $other = $this->makeSiteFor($publisher, $publisherUser, ['primary_domain' => 'other-site.com']);
        $other->update(['status' => SiteStatus::Active]);
        $lines = app(SupplyChainStandardsContract::class)->adsTxtForSite($other)['lines'];

        $this->assertContains($master->raw_record, $lines);
        $this->assertNotContains($demand->raw_record, $lines);
    }

    public function test_canonical_sorting_is_deterministic_and_placeholder_only_when_no_final_authorizations(): void
    {
        $z = $this->master('zeta.exchange.com', 'z', 'DIRECT', null);
        $a = $this->master('alpha.exchange.com', 'a', 'RESELLER', null);
        $contract = app(SupplyChainStandardsContract::class);
        $first = $contract->adsTxtForSite($this->site)['lines'];
        $second = $contract->adsTxtForSite($this->site)['lines'];
        $this->assertSame($first, $second);
        $this->assertSame([$a->raw_record, $z->raw_record], array_values(array_filter($first, fn ($line) => in_array($line, [$a->raw_record, $z->raw_record], true))));

        app(PlatformAdsTxtService::class)->disable($a, $this->admin);
        app(PlatformAdsTxtService::class)->disable($z, $this->admin);
        $content = app(SupplyChainArtifactBuilder::class)->adsTxtForSite($this->site);
        $this->assertStringContainsString('placeholder.example.com, placeholder, DIRECT, placeholder', $content);

        $real = $this->master('real.exchange.com', 'real', 'DIRECT', null);
        $content = app(SupplyChainArtifactBuilder::class)->adsTxtForSite($this->site);
        $this->assertStringContainsString($real->raw_record, $content);
        $this->assertStringNotContainsString('placeholder.example.com, placeholder, DIRECT, placeholder', $content);
    }

    public function test_master_admin_requires_password_reason_and_exact_impact_confirmation(): void
    {
        $service = app(PlatformAdsTxtService::class);
        $record = $service->create([
            'advertising_system_domain' => 'admin.exchange.com',
            'publisher_account_id' => 'admin-seat',
            'relationship' => 'DIRECT',
        ], $this->admin);
        $service->review($record, SupplyChainReviewStatus::Verified, $this->admin);
        $impact = $service->impactedSiteCount();

        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.compliance.ads-txt.master.enable', $record), [])
            ->assertSessionHasErrors(['current_password', 'reason', 'impact_confirmation']);
        $this->assertSame('DISABLED', $record->refresh()->status);

        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.compliance.ads-txt.master.enable', $record), [
                'current_password' => 'password',
                'reason' => 'Approve reviewed platform-wide authorization.',
                'impact_confirmation' => 'WRONG',
            ])->assertSessionHasErrors('impact_confirmation');
        $this->assertSame('DISABLED', $record->refresh()->status);

        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.compliance.ads-txt.master.enable', $record), [
                'current_password' => 'password',
                'reason' => 'Approve reviewed platform-wide authorization.',
                'impact_confirmation' => 'ENABLE '.$impact.' SITES',
            ])->assertRedirect();
        $this->assertSame('ACTIVE', $record->refresh()->status);
    }

    public function test_master_bulk_import_accepts_full_file_activates_valid_rows_and_reports_bad_rows(): void
    {
        $this->withoutExceptionHandling();
        StaticGlobalArtifactChange::query()->delete();
        $contents = implode("\n", [
            '# supplied by demand partner',
            'OWNERDOMAIN=publisher.example',
            'alpha.exchange.com, seat-1, DIRECT, ca-one',
            'beta.exchange.com, seat-2, reseller',
            'alpha.exchange.com, seat-1, DIRECT, ca-one',
            'broken row',
        ]);

        $response = $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.compliance.ads-txt.master.import'), [
                'ads_txt_records' => $contents,
            ]);

        $response->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('ads_txt_import_report', fn (array $report): bool =>
            $report['created'] === 2
            && $report['updated'] === 0
            && $report['reactivated'] === 0
            && $report['duplicates'] === 1
            && $report['invalid_total'] === 1
        );
        $this->assertDatabaseCount('platform_ads_txt_records', 2);
        $this->assertSame(2, PlatformAdsTxtRecord::query()->where('status', 'ACTIVE')->where('review_status', SupplyChainReviewStatus::Verified->value)->count());
        $this->assertContains('alpha.exchange.com, seat-1, DIRECT, ca-one', app(SupplyChainStandardsContract::class)->adsTxtForSite($this->site)['lines']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'supply_chain.platform_ads_txt.bulk_imported', 'actor_id' => $this->admin->id]);
        $this->assertDatabaseCount('static_global_artifact_changes', 1);
        $files = app(SupplyChainArtifactBuilder::class)->files();
        $this->assertArrayHasKey('sellers.json', $files);
        $this->assertArrayHasKey('supply/sellers.json', $files);
        $this->assertSame($files['sellers.json'], $files['supply/sellers.json']);
    }

    public function test_master_quick_add_merges_conflicts_reactivates_existing_and_never_blocks_valid_rows(): void
    {
        $this->withoutExceptionHandling();
        $existing = $this->master('replace.exchange.com', 'seat-x', 'DIRECT', 'old-ca');
        $service = app(PlatformAdsTxtService::class);
        $disabled = $service->create([
            'advertising_system_domain' => 'disabled.exchange.com',
            'publisher_account_id' => 'seat-y',
            'relationship' => 'RESELLER',
        ], $this->admin);

        $response = $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.compliance.ads-txt.master.import'), [
                'ads_txt_records' => implode("\n", [
                    'replace.exchange.com, seat-x, DIRECT, old-ca',
                    'replace.exchange.com, seat-x, RESELLER, new-ca',
                    'disabled.exchange.com, seat-y, RESELLER',
                    'new.exchange.com, seat-z, DIRECT, ca-new',
                    'new.exchange.com, seat-z, DIRECT, ca-new',
                    'this row is invalid',
                ]),
            ]);

        $response->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('ads_txt_import_report', fn (array $report): bool =>
            $report['created'] === 1
            && $report['updated'] === 1
            && $report['reactivated'] === 1
            && $report['skipped'] === 0
            && $report['duplicates'] === 1
            && $report['superseded'] === 1
            && $report['invalid_total'] === 1
        );

        $this->assertSame('replace.exchange.com, seat-x, RESELLER, new-ca', $existing->refresh()->raw_record);
        $this->assertSame('ACTIVE', $existing->status);
        $this->assertSame(SupplyChainReviewStatus::Verified, $existing->review_status);
        $this->assertSame('ACTIVE', $disabled->refresh()->status);
        $this->assertSame(SupplyChainReviewStatus::Verified, $disabled->review_status);
        $this->assertDatabaseHas('platform_ads_txt_records', [
            'advertising_system_domain' => 'new.exchange.com',
            'publisher_account_id' => 'seat-z',
            'status' => 'ACTIVE',
            'review_status' => SupplyChainReviewStatus::Verified->value,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'supply_chain.platform_ads_txt.bulk_updated',
            'auditable_id' => $existing->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'supply_chain.platform_ads_txt.bulk_reactivated',
            'auditable_id' => $disabled->id,
        ]);
    }

    public function test_demand_bulk_import_creates_all_valid_rows_for_one_account_scope(): void
    {
        $network = DemandNetwork::query()->where('code', 'MGID')->firstOrFail();
        $account = DemandAccount::withoutGlobalScopes()->create([
            'organization_id' => $this->site->organization_id,
            'demand_network_id' => $network->id,
            'publisher_id' => $this->site->publisher_id,
            'name' => 'Bulk demand account',
            'scope' => DemandAccountScope::Publisher,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        DemandSite::withoutGlobalScopes()->create([
            'organization_id' => $account->organization_id,
            'demand_account_id' => $account->id,
            'site_id' => $this->site->id,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $result = app(AdsTxtRecordManager::class)->bulkImport(implode("\n", [
            'one.network.example, public-1, DIRECT, cert-one',
            'two.network.example, public-2, RESELLER',
            'not,valid',
        ]), $account->id, null, $this->admin);

        $this->assertSame(2, $result['created']);
        $this->assertCount(1, $result['invalid']);
        $this->assertSame(2, DemandAdsTxtRecord::withoutGlobalScopes()->where('demand_account_id', $account->id)->count());
        $this->assertSame(2, DemandAdsTxtRecord::withoutGlobalScopes()->where('demand_account_id', $account->id)->where('review_status', SupplyChainReviewStatus::Verified->value)->count());
        $this->assertTrue(AuditLog::query()->where('event', 'supply_chain.ads_txt.bulk_imported')->where('actor_id', $this->admin->id)->exists());
    }

    public function test_bulk_parser_enforces_large_file_limit_without_one_by_one_processing(): void
    {
        $contents = collect(range(1, AdsTxtBulkParser::MAX_LINES + 1))
            ->map(fn (int $line): string => "network{$line}.example, seat-{$line}, DIRECT")
            ->implode("\n");

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(AdsTxtBulkParser::class)->parse($contents);
    }

    private function master(string $domain, string $seller, string $relationship, ?string $authority): PlatformAdsTxtRecord
    {
        $service = app(PlatformAdsTxtService::class);
        $record = $service->create([
            'advertising_system_domain' => $domain,
            'publisher_account_id' => $seller,
            'relationship' => $relationship,
            'certification_authority_id' => $authority,
        ], $this->admin);
        $service->review($record, SupplyChainReviewStatus::Verified, $this->admin);
        return $service->enable($record, $this->admin);
    }

    private function bidderRecord(string $domain, string $seller, string $relationship, ?string $authority): void
    {
        $bidder = PrebidBidder::withoutGlobalScopes()->where('code', 'msft')->firstOrFail();
        $manager = app(PrebidManager::class);
        $account = $manager->addAccount($bidder, ['name' => 'Master duplicate bidder', 'enabled' => true], $this->admin);
        $manager->assignToSite($account, $this->site, ['enabled' => true], $this->admin);
        $service = app(BidderAdsTxtService::class);
        $record = $service->create($account, null, [
            'advertising_system_domain' => $domain,
            'publisher_account_id' => $seller,
            'relationship' => $relationship,
            'certification_authority_id' => $authority,
        ], $this->admin);
        $service->review($record, SupplyChainReviewStatus::Verified, $this->admin);
    }

    private function demandRecord(string $domain, string $seller, string $relationship, ?string $authority): DemandAdsTxtRecord
    {
        $network = DemandNetwork::query()->where('code', 'MGID')->firstOrFail();
        $account = DemandAccount::withoutGlobalScopes()->create([
            'organization_id' => $this->site->organization_id,
            'demand_network_id' => $network->id,
            'publisher_id' => $this->site->publisher_id,
            'name' => 'Tenant demand',
            'scope' => DemandAccountScope::Publisher,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        DemandSite::withoutGlobalScopes()->create([
            'organization_id' => $account->organization_id,
            'demand_account_id' => $account->id,
            'site_id' => $this->site->id,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        $line = implode(', ', array_filter([$domain, $seller, $relationship, $authority], fn ($value) => filled($value)));
        return DemandAdsTxtRecord::withoutGlobalScopes()->create([
            'organization_id' => $account->organization_id,
            'demand_account_id' => $account->id,
            'site_id' => null,
            'domain' => $domain,
            'publisher_account_id' => $seller,
            'relationship' => $relationship,
            'certification_authority_id' => $authority,
            'record_hash' => hash('sha256', $line),
            'raw_record' => $line,
            'status' => 'ACTIVE',
            'review_status' => SupplyChainReviewStatus::Verified,
            'source' => 'MANUAL',
            'last_verified_at' => now(),
            'reviewed_at' => now(),
            'reviewed_by' => $this->admin->id,
        ]);
    }

    private function publicDns(): void
    {
        $this->app->instance(DnsResolver::class, new class implements DnsResolver {
            public function addresses(string $host): array { return ['8.8.8.8']; }
        });
    }
}
