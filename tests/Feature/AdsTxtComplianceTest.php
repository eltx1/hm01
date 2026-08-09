<?php

namespace Tests\Feature;

use App\Enums\AdsTxtComplianceStatus;
use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\DemandAccount;
use App\Models\DemandAdsTxtRecord;
use App\Models\DemandNetwork;
use App\Models\DemandSite;
use App\Models\Site;
use App\Models\SupplyChainCheck;
use App\Services\Compliance\AdsTxtComplianceService;
use App\Services\Compliance\AdsTxtRecordManager;
use App\Services\Compliance\AdsTxtVerifier;
use App\Services\Network\Contracts\DnsResolver;
use App\Services\SupplyChain\SupplyChainArtifactBuilder;
use App\Services\SupplyChain\SupplyChainInvariantService;
use Database\Seeders\DemandNetworkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class AdsTxtComplianceTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;

    private $publisherUser;

    private $publisherViewer;

    private $site;

    private $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed(DemandNetworkSeeder::class);
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Publisher Org');
        $this->publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $this->publisherViewer = $this->makeUser($publisherOrganization, RoleName::PublisherViewer);
        $publisher = $this->makePublisherFor($this->publisherUser, ['business_domain' => 'publisher-owner.example']);
        $this->site = $this->makeSiteFor($publisher, $this->publisherUser, ['primary_domain' => 'publisher-site.example']);
        $this->site->domains()->where('is_primary', true)->update(['verification_status' => 'VERIFIED', 'verified_at' => now()]);

        $network = DemandNetwork::query()->where('code', 'MGID')->firstOrFail();
        $this->account = DemandAccount::withoutGlobalScopes()->create([
            'organization_id' => $horus->id,
            'demand_network_id' => $network->id,
            'name' => 'Managed demand',
            'scope' => DemandAccountScope::HorusMedia,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => true,
            'revenue_share_percent' => 20,
            'fallback_priority' => 10,
        ]);
        DemandSite::withoutGlobalScopes()->create([
            'organization_id' => $this->site->organization_id,
            'demand_account_id' => $this->account->id,
            'site_id' => $this->site->id,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => true,
            'integration_mode' => DemandIntegrationMode::DirectJs,
        ]);
        $this->record($this->site, 'seller-100');
        $this->publicDns();
    }

    public function test_admin_and_publisher_surfaces_show_computed_content_with_permissions_and_isolation(): void
    {
        $admin = $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $admin->get(route('admin.compliance.ads-txt.index'))->assertOk()->assertSee('Ads.txt Compliance Center')->assertSee('publisher-site.example');
        $admin->get(route('admin.compliance.ads-txt.show', $this->site))->assertOk()->assertSee('seller-100')->assertSee('Canonical required file');

        $this->actingAs($this->publisherUser)->get(route('publisher.ads-txt.index'))
            ->assertOk()->assertSee('Ads.txt &amp; Compliance', false)->assertSee('exchange.example, seller-100, DIRECT, abc123');
        $this->actingAs($this->publisherUser)->post(route('admin.compliance.ads-txt.records.store'), [
            'demand_account_id' => $this->account->id,
        ])->assertForbidden();
        $this->actingAs($this->publisherViewer)->post(route('publisher.ads-txt.verify', $this->site))->assertForbidden();

        $otherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Other Publisher');
        $otherUser = $this->makeUser($otherOrganization, RoleName::PublisherAdmin);
        $this->actingAs($otherUser)->get(route('publisher.ads-txt.download', $this->site))->assertNotFound();
        $this->actingAs($otherUser)->post(route('publisher.ads-txt.verify', $this->site))->assertNotFound();
    }

    public function test_successful_manual_verification_computes_diff_audits_and_deduplicates_snapshots(): void
    {
        $canonical = app(SupplyChainArtifactBuilder::class)->adsTxtForSite($this->site);
        Http::fake(['https://publisher-site.example/ads.txt' => Http::response($canonical, 200, ['Content-Type' => 'text/plain; charset=utf-8'])]);

        $first = app(AdsTxtVerifier::class)->verify($this->site, 'ADMIN', $this->admin);
        $second = app(AdsTxtVerifier::class)->verify($this->site, 'ADMIN', $this->admin);

        $this->assertSame(AdsTxtComplianceStatus::Compliant->value, $first->status);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $second->occurrence_count);
        $this->assertCount(1, data_get($second->findings, 'comparison.correct'));
        $this->assertDatabaseCount('supply_chain_checks', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'supply_chain.ads_txt.manually_verified', 'auditable_id' => $this->site->id]);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent') && $request->hasHeader('Accept', 'text/plain'));
    }

    public function test_missing_invalid_content_type_timeout_and_oversize_have_safe_failure_states(): void
    {
        $this->fakeHttp(['*' => Http::response('', 404, ['Content-Type' => 'text/plain'])]);
        $this->assertSame(AdsTxtComplianceStatus::Missing->value, app(AdsTxtVerifier::class)->verify($this->site)->status);

        $this->fakeHttp(['*' => Http::response('<html>wrong</html>', 200, ['Content-Type' => 'text/html'])]);
        $this->assertSame('INVALID_CONTENT_TYPE', app(AdsTxtVerifier::class)->verify($this->site)->findings['fetch']['error_code']);

        $this->fakeHttp(['*' => Http::failedConnection()]);
        $this->assertSame('CONNECTION_FAILED', app(AdsTxtVerifier::class)->verify($this->site)->findings['fetch']['error_code']);

        config(['ads-txt.max_response_bytes' => 10]);
        $this->fakeHttp(['*' => Http::response(str_repeat('x', 11), 200, ['Content-Type' => 'text/plain'])]);
        $this->assertSame('RESPONSE_TOO_LARGE', app(AdsTxtVerifier::class)->verify($this->site)->findings['fetch']['error_code']);
    }

    public function test_redirects_are_revalidated_and_only_verified_site_domains_are_allowed(): void
    {
        $redirectDomain = $this->site->domains()->create([
            'organization_id' => $this->site->organization_id,
            'domain' => 'ads.publisher-site.example',
            'verification_status' => 'VERIFIED',
            'verification_token' => 'verified-token',
            'verified_at' => now(),
        ]);
        $canonical = app(SupplyChainArtifactBuilder::class)->adsTxtForSite($this->site);
        Http::fake(function (Request $request) use ($canonical) {
            return str_contains($request->url(), 'ads.publisher-site.example')
                ? Http::response($canonical, 200, ['Content-Type' => 'text/plain'])
                : Http::response('', 302, ['Location' => 'https://ads.publisher-site.example/ads.txt']);
        });

        $allowed = app(AdsTxtVerifier::class)->verify($this->site);
        $this->assertSame(AdsTxtComplianceStatus::Compliant->value, $allowed->status);
        $this->assertSame('https://ads.publisher-site.example/ads.txt', $allowed->final_url);

        $this->fakeHttp(['*' => Http::response('', 302, ['Location' => 'https://attacker.example/ads.txt'])]);
        $blocked = app(AdsTxtVerifier::class)->verify($this->site);
        $this->assertSame(AdsTxtComplianceStatus::Unreachable->value, $blocked->status);
        $this->assertSame('UNAUTHORIZED_REDIRECT', $blocked->findings['fetch']['error_code']);
        $this->assertNotNull($redirectDomain->id);
    }

    public function test_private_dns_and_unverified_domains_are_rejected_before_any_http_request(): void
    {
        Http::fake();
        $this->app->instance(DnsResolver::class, new class implements DnsResolver
        {
            public function addresses(string $host): array
            {
                return ['127.0.0.1', '169.254.169.254'];
            }
        });
        $private = app(AdsTxtVerifier::class)->verify($this->site);
        $this->assertSame('UNSAFE_TARGET', $private->findings['fetch']['error_code']);
        Http::assertNothingSent();

        $this->publicDns();
        $this->site->domains()->update(['verification_status' => 'PENDING', 'verified_at' => null]);
        $unverified = app(AdsTxtVerifier::class)->verify($this->site);
        $this->assertSame('DOMAIN_NOT_VERIFIED', $unverified->findings['fetch']['error_code']);
        Http::assertNothingSent();
    }

    public function test_structured_record_management_bulk_assignment_and_audit_are_safe(): void
    {
        $manager = app(AdsTxtRecordManager::class);
        $second = $this->makeSiteFor($this->site->publisher, $this->publisherUser, ['primary_domain' => 'second-site.example']);
        DemandSite::withoutGlobalScopes()->create([
            'organization_id' => $second->organization_id, 'demand_account_id' => $this->account->id, 'site_id' => $second->id,
            'approval_status' => DemandApprovalStatus::Approved, 'is_enabled' => true, 'is_default' => false,
            'integration_mode' => DemandIntegrationMode::DirectJs,
        ]);
        $data = [
            'demand_account_id' => $this->account->id, 'domain' => 'bulk.example',
            'publisher_account_id' => 'bulk-1', 'relationship' => 'RESELLER', 'certification_authority_id' => null,
        ];

        $bulk = $manager->bulkAssign($data, [$this->site->id, $second->id], $this->admin);
        $this->assertSame(['created' => 2, 'skipped' => 0], $bulk);
        $this->assertDatabaseHas('audit_logs', ['event' => 'supply_chain.ads_txt.bulk_assigned']);

        $record = $manager->create(array_merge($data, ['site_id' => $this->site->id, 'publisher_account_id' => 'manual-2']), $this->admin);
        $manager->update($record, array_merge($data, ['site_id' => $this->site->id, 'publisher_account_id' => 'manual-3']), $this->admin);
        $manager->disable($record->refresh(), $this->admin);
        $this->assertDatabaseHas('demand_ads_txt_records', ['id' => $record->id, 'publisher_account_id' => 'manual-3', 'status' => 'DISABLED']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'supply_chain.ads_txt.record_updated', 'auditable_id' => $record->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'supply_chain.ads_txt.record_disabled', 'auditable_id' => $record->id]);

        $unmapped = $this->makeSiteFor($this->site->publisher, $this->publisherUser, ['primary_domain' => 'unmapped.example']);
        $this->expectException(ValidationException::class);
        $manager->bulkAssign(array_merge($data, ['publisher_account_id' => 'unsafe']), [$this->site->id, $unmapped->id], $this->admin);
    }

    public function test_scheduled_command_uses_verifier_without_flooding_manual_audit_log(): void
    {
        $canonical = app(SupplyChainArtifactBuilder::class)->adsTxtForSite($this->site);
        Http::fake(['*' => Http::response($canonical, 200, ['Content-Type' => 'text/plain'])]);

        $this->artisan('supply-chain:check', ['--site' => $this->site->id])->assertSuccessful();

        $this->assertDatabaseHas('supply_chain_checks', ['site_id' => $this->site->id, 'trigger' => 'SCHEDULED', 'status' => 'COMPLIANT']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'supply_chain.ads_txt.manually_verified']);
    }

    public function test_history_retains_only_the_configured_number_of_distinct_snapshots(): void
    {
        config(['ads-txt.history_snapshots' => 2]);
        $canonical = app(SupplyChainArtifactBuilder::class)->adsTxtForSite($this->site);
        $attempt = 0;
        $this->fakeHttp(function () use (&$attempt, $canonical) {
            $attempt++;

            return Http::response($canonical."extra.example, extra-{$attempt}, DIRECT\n", 200, ['Content-Type' => 'text/plain']);
        });

        app(AdsTxtVerifier::class)->verify($this->site);
        app(AdsTxtVerifier::class)->verify($this->site);
        app(AdsTxtVerifier::class)->verify($this->site);

        $this->assertSame(2, SupplyChainCheck::withoutGlobalScopes()->where('site_id', $this->site->id)->count());
    }

    public function test_manual_rechecks_are_throttled_per_user_and_site(): void
    {
        $canonical = app(SupplyChainArtifactBuilder::class)->adsTxtForSite($this->site);
        Http::fake(['*' => Http::response($canonical, 200, ['Content-Type' => 'text/plain'])]);
        $this->actingAs($this->publisherUser);

        $this->post(route('publisher.ads-txt.verify', $this->site))->assertRedirect();
        $this->post(route('publisher.ads-txt.verify', $this->site))->assertRedirect();
        $this->post(route('publisher.ads-txt.verify', $this->site))->assertTooManyRequests();
    }

    public function test_canonical_generation_is_deterministic_and_stale_status_is_computed(): void
    {
        $builder = app(SupplyChainArtifactBuilder::class);
        $this->assertSame($builder->adsTxtForSite($this->site), $builder->adsTxtForSite($this->site));
        $canonical = $builder->adsTxtForSite($this->site);
        Http::fake(['*' => Http::response($canonical, 200, ['Content-Type' => 'text/plain'])]);
        $check = app(AdsTxtVerifier::class)->verify($this->site);
        $this->record($this->site, 'seller-200');
        $changed = app(AdsTxtComplianceService::class)->summary($this->site);
        $this->assertSame(AdsTxtComplianceStatus::Stale->value, $changed['status']);
        $this->assertSame(1, $changed['missing_count']);
        $check->update(['checked_at' => now()->subDays(8)]);

        $this->assertSame(AdsTxtComplianceStatus::Stale->value, app(AdsTxtComplianceService::class)->summary($this->site)['status']);
    }

    private function record(Site $site, string $sellerId): DemandAdsTxtRecord
    {
        $attributes = app(SupplyChainInvariantService::class)->normalizeDemandRecord($this->account, $site, [
            'domain' => 'exchange.example', 'publisher_account_id' => $sellerId,
            'relationship' => 'DIRECT', 'certification_authority_id' => 'abc123',
        ]);

        return DemandAdsTxtRecord::withoutGlobalScopes()->create($attributes + [
            'demand_account_id' => $this->account->id, 'site_id' => $site->id,
            'status' => 'ACTIVE', 'source' => 'CONNECTOR', 'last_verified_at' => now(),
        ]);
    }

    private function publicDns(): void
    {
        $this->app->instance(DnsResolver::class, new class implements DnsResolver
        {
            public function addresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    private function fakeHttp(callable|array|null $callback = null): void
    {
        Http::swap(new Factory($this->app['events']));
        Http::fake($callback);
    }
}
