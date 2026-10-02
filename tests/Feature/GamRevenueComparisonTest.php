<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\DailyReport;
use App\Models\GamConnection;
use App\Models\FinancialPeriod;
use App\Models\RevenueRule;
use App\Models\RevenueRuleVersion;
use App\Models\ReportImportJob;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Models\Site;
use App\Models\SiteGamReportBinding;
use App\Services\Reporting\GamAdUnitReportClient;
use App\Services\Reporting\GamRevenueComparisonService;
use App\Services\Reporting\ReportDimensionResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class GamRevenueComparisonTest extends TestCase
{
    use RefreshDatabase, InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites;

    private function context(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00 UTC'));
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user);
        $site = $this->makeSiteFor($publisher, $user, [
            'display_name' => 'Synthetic News', 'primary_domain' => 'news.test.example',
        ]);
        $gam = $this->makeGamConnection($admin->organization, $admin, ['network_code' => '123']);
        $source = ReportSource::query()->create(['code' => 'GAM_AD_UNIT', 'name' => 'Synthetic GAM', 'is_enabled' => true]);
        $bindingId = (string) Str::ulid();
        $connection = ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $user->organization_id, 'report_source_id' => $source->id,
            'name' => 'Synthetic site source', 'connection_type' => 'SITE_GAM_AD_UNIT', 'connection_id' => $bindingId,
            'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'ACTIVE', 'is_enabled' => true,
            'configuration' => ['google_jobs' => ['scheduler-checkpoint' => ['id' => '999']]],
        ]);
        $binding = SiteGamReportBinding::withoutGlobalScopes()->create([
            'id' => $bindingId, 'organization_id' => $user->organization_id, 'site_id' => $site->id,
            'gam_connection_id' => $gam->id, 'report_source_connection_id' => $connection->id,
            'active_site_id' => $site->id, 'active_unit_key' => '123:456', 'network_code' => '123',
            'ad_unit_id' => '456', 'ad_unit_name' => 'Synthetic unit', 'ad_unit_code' => 'synthetic', 'starts_on' => '2026-09-01',
        ]);
        $job = ReportImportJob::withoutGlobalScopes()->create([
            'organization_id' => $user->organization_id, 'report_source_connection_id' => $connection->id,
            'import_type' => 'API', 'granularity' => 'DAILY', 'finality' => 'FINALIZED', 'status' => 'COMPLETED',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-02', 'idempotency_key' => hash('sha256', 'synthetic-fixture'),
        ]);
        $dimension = app(ReportDimensionResolver::class)->resolve(['site_id' => $site->id, 'gam_connection_id' => $gam->id, 'gam_ad_unit_id' => '456']);
        $period = FinancialPeriod::create(['period_key' => '2026-09', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'currency' => 'USD', 'status' => 'OPEN']);
        $rule = RevenueRule::withoutGlobalScopes()->create(['organization_id' => $user->organization_id, 'name' => 'Synthetic original rule', 'scope_type' => 'WEBSITE', 'scope_id' => $site->id, 'is_active' => true, 'effective_from' => '2026-09-01']);
        $version = RevenueRuleVersion::create(['revenue_rule_id' => $rule->id, 'version' => 1, 'publisher_share_bp' => 7000, 'horus_share_bp' => 2500, 'mcm_partner_share_bp' => 500, 'effective_from' => '2026-09-01', 'currency' => null]);
        $newVersion = RevenueRuleVersion::create(['revenue_rule_id' => $rule->id, 'version' => 2, 'publisher_share_bp' => 5000, 'horus_share_bp' => 5000, 'mcm_partner_share_bp' => 0, 'effective_from' => '2026-10-01', 'currency' => 'USD']);
        $rule->update(['current_version_id' => $newVersion->id]);
        DailyReport::withoutGlobalScopes()->create([
            'organization_id' => $user->organization_id, 'report_source_connection_id' => $connection->id,
            'report_import_job_id' => $job->id, 'report_dimension_id' => $dimension->id, 'report_date' => '2026-09-01',
            'currency' => 'USD', 'finality' => 'FINALIZED', 'settlement_eligible' => true,
            'financial_period_id' => $period->id, 'revenue_rule_version_id' => $version->id,
            'gross_revenue_minor' => 123, 'demand_partner_deductions_minor' => 10, 'net_revenue_minor' => 113,
            'publisher_earnings_minor' => 79, 'horus_earnings_minor' => 29, 'mcm_partner_earnings_minor' => 5,
            'impressions' => 25, 'source_row_hash' => hash('sha256', 'synthetic-row'),
        ]);
        $google = new class extends GamAdUnitReportClient
        {
            public array $calls = [];
            public array $queries = [];
            public bool $unsupported = false;
            public string $networkTimezone = 'UTC';
            public function __construct() {}
            public function call(GamConnection $connection, string $service, string $method, array $payload = []): array
            {
                $this->calls[] = $method;
                if ($method === 'getCurrentNetwork') return ['networkCode' => '123', 'timeZone' => $this->networkTimezone, 'currencyCode' => 'AED'];
                if ($method === 'runReportJob') {
                    if ($this->unsupported) throw new \RuntimeException('COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS private-secret-must-not-escape');
                    $this->queries[] = $payload['reportJob']['reportQuery'];
                    return ['id' => (string) count($this->queries), 'reportQuery' => ['reportCurrency' => 'USD']];
                }
                if ($method === 'getReportJobStatus') return ['value' => 'COMPLETED'];
                throw new \RuntimeException('Unexpected call');
            }
            public function download(GamConnection $connection, string $jobId): string
            {
                $query = $this->queries[(int) $jobId - 1];
                $columns = array_map(fn ($key) => 'Column.'.$key, $query['columns']);
                $selected = ['AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE' => 'USD 2005000', 'AD_EXCHANGE_LINE_ITEM_LEVEL_IMPRESSIONS' => '20', 'AD_EXCHANGE_LINE_ITEM_LEVEL_CLICKS' => '2'];
                $other = ['AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE' => 'USD 9000000', 'AD_EXCHANGE_LINE_ITEM_LEVEL_IMPRESSIONS' => '90', 'AD_EXCHANGE_LINE_ITEM_LEVEL_CLICKS' => '9'];
                return implode(',', ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Dimension.SITE_NAME', ...$columns])."\n"
                    .implode(',', ['2026-09-01', '456', 'news.test.example', ...array_map(fn ($column) => $selected[$column], $query['columns'])])."\n"
                    .implode(',', ['2026-09-01', '456', 'other.test.example', ...array_map(fn ($column) => $other[$column], $query['columns'])])."\n";
            }
        };
        $this->app->instance(GamAdUnitReportClient::class, $google);
        return [$admin, $user, $site, $binding, $connection, $google];
    }

    private function startPayload(Site $site): array
    {
        return ['site_id' => $site->id, 'from' => '2026-09-01', 'to' => '2026-09-02'];
    }

    private function financialSnapshot(): array
    {
        $tables = ['sites', 'report_source_connections', 'site_gam_report_bindings', 'report_import_jobs', 'daily_reports', 'hourly_reports', 'monthly_reports', 'financial_periods', 'revenue_rules', 'revenue_rule_versions', 'revenue_adjustments', 'publisher_statements', 'publisher_payments'];
        return collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public function test_private_preview_is_idempotent_and_never_changes_financial_or_import_state(): void
    {
        [$admin, , $site, , , $google] = $this->context();
        $before = $this->financialSnapshot();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $response = $this->post(route('admin.reporting.gam-comparison.start'), $this->startPayload($site))->assertRedirect();
        $entry = array_values(session('private_gam_revenue_comparisons'))[0];
        $this->post(route('admin.reporting.gam-comparison.start'), $this->startPayload($site))->assertRedirect($response->headers->get('Location'));
        $this->assertCount(1, $google->queries);
        $query = $google->queries[0];
        $this->assertSame(['DATE', 'AD_UNIT_ID', 'SITE_NAME'], $query['dimensions']);
        $this->assertSame('WHERE AD_UNIT_ID = :unit', $query['statement']['query']);
        $this->assertSame(array_keys(\App\Services\Reporting\SiteGamReportMetrics::FINANCE_COLUMNS), $query['columns']);
        $this->assertStringNotContainsString('TOTAL_LINE_ITEM', json_encode($query));
        $this->assertSame('456', $query['statement']['values'][0]['value']['value']);
        $this->assertSame('USD', $query['reportCurrency']);
        $this->assertSame('PUBLISHER', $query['timeZoneType']);
        $this->assertSame('FLAT', $query['adUnitView']);
        $this->travel(16)->seconds();
        $this->post(route('admin.reporting.gam-comparison.poll', $entry['id']))->assertRedirect();
        $calls = count($google->calls);
        $this->post(route('admin.reporting.gam-comparison.poll', $entry['id']))->assertRedirect();
        $this->assertCount($calls, $google->calls);
        $download = $this->get(route('admin.reporting.gam-comparison.download', $entry['id']))->assertOk()
            ->assertJsonPath('result.days.0.fresh.revenue_micros', 2005000)
            ->assertJsonPath('result.days.0.fresh.gross_revenue_minor', 201)
            ->assertJsonPath('result.days.0.gross_delta_minor', 78)
            ->assertJsonPath('result.days.0.projected.publisher_earnings_minor', 133)
            ->assertJsonPath('result.days.0.projected.horus_earnings_minor', 49)
            ->assertJsonPath('result.days.0.projected.mcm_partner_earnings_minor', 9)
            ->assertJsonPath('result.days.0.flags', [])
            ->assertJsonPath('result.days.0.stored_metric_bases', ['LEGACY_TOTAL_UNVERSIONED'])
            ->assertJsonPath('result.days.0.fresh_metric_basis', 'AD_EXCHANGE_V1')
            ->assertJsonPath('result.days.0.basis_change', true)
            ->assertJsonPath('result.days.1.fresh', null)
            ->assertJsonPath('result.days.1.gross_delta_minor', null)
            ->assertJsonPath('result.replacement_approved', false)
            ->assertDontSee('other.test.example')->assertDontSee('scheduler-checkpoint');
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('No parent-domain')->assertSee('2.01')->assertSee('Metric basis changed or unversioned');
        $this->post(route('admin.reporting.gam-comparison.discard', $entry['id']))->assertRedirect();
        $this->get(route('admin.reporting.gam-comparison.download', $entry['id']))->assertNotFound();
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_all_endpoints_require_horus_reporting_permission_and_the_same_actor_session(): void
    {
        [$admin, $publisher, $site] = $this->context();
        $id = (string) Str::uuid();
        $this->get(route('admin.reporting.gam-comparison'))->assertRedirect(route('admin.login'));
        $this->actingAs($publisher)->get(route('admin.reporting.gam-comparison'))->assertForbidden();
        foreach (['start' => $this->startPayload($site), 'poll' => [], 'discard' => []] as $action => $data) {
            $this->post(route('admin.reporting.gam-comparison.'.$action, $action === 'start' ? [] : $id), $data)->assertForbidden();
        }
        $this->get(route('admin.reporting.gam-comparison.download', $id))->assertForbidden();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $this->post(route('admin.reporting.gam-comparison.start'), $this->startPayload($site))->assertRedirect();
        $entry = array_values(session('private_gam_revenue_comparisons'))[0];
        $other = $this->makeUser($admin->organization, RoleName::SuperAdmin);
        $this->actingAs($other)->get(route('admin.reporting.gam-comparison.download', $entry['id']))->assertNotFound();
        $this->post(route('admin.reporting.gam-comparison.poll', $entry['id']))->assertNotFound();
        $this->post(route('admin.reporting.gam-comparison.discard', $entry['id']))->assertNotFound();
        $admin->roles()->detach();
        $this->actingAs($admin->fresh())->get(route('admin.reporting.gam-comparison'))->assertForbidden();
    }

    public function test_binding_facts_rules_and_closed_period_changes_invalidate_saved_evidence(): void
    {
        [$admin, , $site, $binding] = $this->context();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $this->post(route('admin.reporting.gam-comparison.start'), $this->startPayload($site))->assertRedirect();
        $entry = array_values(session('private_gam_revenue_comparisons'))[0];
        $binding->update(['ad_unit_id' => '457']);
        $this->postJson(route('admin.reporting.gam-comparison.poll', $entry['id']))->assertUnprocessable();
        $binding->update(['ad_unit_id' => '456']);
        // Validate each independently against freshly captured evidence.
        foreach (['fact', 'rule', 'period'] as $kind) {
            $entry['context'] = app(GamRevenueComparisonService::class)->context($site, '2026-09-01', '2026-09-02');
            $entry['snapshot'] = app(GamRevenueComparisonService::class)->snapshot($entry['context']);
            $this->withSession(['private_gam_revenue_comparisons' => [$entry['id'] => $entry]]);
            match ($kind) {
                'fact' => DailyReport::withoutGlobalScopes()->first()->increment('gross_revenue_minor'),
                'rule' => RevenueRuleVersion::where('version', 1)->update(['publisher_share_bp' => 6000]),
                'period' => FinancialPeriod::query()->update(['status' => 'CLOSED']),
            };
            $this->getJson(route('admin.reporting.gam-comparison.download', $entry['id']))->assertUnprocessable()->assertJsonValidationErrors('comparison');
        }
    }

    public function test_failed_uncertain_and_interrupted_attempts_are_not_resubmitted(): void
    {
        [$admin, , $site, , , $google] = $this->context();
        $google->unsupported = true;
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $this->post(route('admin.reporting.gam-comparison.start'), $this->startPayload($site))->assertRedirect();
        $entry = array_values(session('private_gam_revenue_comparisons'))[0];
        $this->assertSame('FAILED', $entry['job']['status']);
        $this->post(route('admin.reporting.gam-comparison.start'), $this->startPayload($site))->assertRedirect();
        $this->assertSame(1, count(array_filter($google->calls, fn ($method) => $method === 'runReportJob')));
        $this->get(route('admin.reporting.gam-comparison', ['comparison' => $entry['id']]))->assertOk()->assertDontSee('private-secret-must-not-escape');
        $entry['job'] = ['status' => 'STARTING'];
        $this->withSession(['private_gam_revenue_comparisons' => [$entry['id'] => $entry]]);
        $this->post(route('admin.reporting.gam-comparison.start'), $this->startPayload($site))->assertRedirect();
        $this->assertSame(1, count(array_filter($google->calls, fn ($method) => $method === 'runReportJob')));
        $this->get(route('admin.reporting.gam-comparison.download', $entry['id']))->assertStatus(409);
    }

    public function test_bounded_dates_network_identity_expiry_and_forged_ids_fail_closed(): void
    {
        [$admin, , $site, , , $google] = $this->context();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        foreach ([['from' => '2026-08-31'], ['to' => '2026-10-09'], ['from' => 'invalid'], ['from' => '2026-10-10', 'to' => '2026-10-10']] as $changes) {
            $this->postJson(route('admin.reporting.gam-comparison.start'), array_replace($this->startPayload($site), $changes))->assertUnprocessable();
        }
        $this->assertCount(0, $google->queries);
        $this->post(route('admin.reporting.gam-comparison.start'), $this->startPayload($site))->assertRedirect();
        $entry = array_values(session('private_gam_revenue_comparisons'))[0];
        $this->get(route('admin.reporting.gam-comparison.download', (string) Str::uuid()))->assertNotFound();
        $google->networkTimezone = 'Asia/Dubai';
        $this->travel(16)->seconds();
        $this->post(route('admin.reporting.gam-comparison.poll', $entry['id']))->assertRedirect();
        $entry = array_values(session('private_gam_revenue_comparisons'))[0];
        $this->assertSame('NETWORK_IDENTITY_CHANGED', $entry['job']['error']);
        $this->travel(31)->minutes();
        $this->get(route('admin.reporting.gam-comparison.download', $entry['id']))->assertNotFound();
    }

    public function test_ambiguous_or_unverifiable_facts_withhold_original_rule_projections(): void
    {
        [$admin, , $site] = $this->context();
        $this->actingAs($admin);
        $service = app(GamRevenueComparisonService::class);
        $context = $service->context($site, '2026-09-01', '2026-09-02');
        $snapshot = $service->snapshot($context);
        $fresh = ['days' => ['2026-09-01' => ['revenue_micros' => 2005000, 'gross_revenue_minor' => 201, 'impressions' => 20, 'clicks' => 2]], 'exact_site_observed' => true, 'excluded_site_rows' => 0];
        foreach ([
            ['fact.revenue_rule_version_id', null, 'UNVERIFIED_ORIGINAL_RULE'],
            ['fact.currency', 'AED', 'MIXED_CURRENCY'],
            ['fact.finality', 'ESTIMATED', 'NON_FINALIZED_FACT'],
            ['fact.publisher_earnings_minor', 80, 'ORIGINAL_ALLOCATION_MISMATCH'],
            ['period.status', 'CLOSED', 'PERIOD_NOT_OPEN'],
            ['rule_version.horus_share_bp', -1, 'UNVERIFIED_ORIGINAL_RULE'],
            ['rule_version.currency', 'EUR', 'UNVERIFIED_ORIGINAL_RULE'],
            ['dimension.site_id', (string) Str::ulid(), 'MIXED_STORED_IDENTITY'],
            ['dimension.external_dimensions', json_encode(['gam_ad_unit_id' => '789']), 'UNVERIFIED_STORED_UNIT'],
            ['dimension.external_dimensions', json_encode(['gam_ad_unit_id' => '456', 'gam_report_site' => 'other.test.example']), 'MIXED_STORED_HOST'],
            ['fact.gross_revenue_minor', 'not-money', 'UNVERIFIED_STORED_AMOUNT'],
            ['rule.effective_from', '2026-10-01', 'UNVERIFIED_ORIGINAL_RULE'],
            ['rule.effective_to', '2026-08-31', 'UNVERIFIED_ORIGINAL_RULE'],
            ['dimension.external_dimensions', json_encode(['gam_ad_unit_id' => '456', 'gam_report_basis' => 'UNRECOGNIZED']), 'UNVERIFIED_STORED_METRIC_BASIS'],
        ] as [$key, $value, $flag]) {
            $changed = $snapshot;
            data_set($changed['facts'][0], $key, $value);
            $day = $service->compare($context, $changed, $fresh)['days'][0];
            $this->assertNull($day['projected'], $flag);
            $this->assertContains($flag, $day['flags']);
            if (in_array($flag, ['MIXED_CURRENCY', 'UNVERIFIED_STORED_UNIT', 'MIXED_STORED_HOST', 'UNVERIFIED_STORED_AMOUNT', 'UNVERIFIED_STORED_METRIC_BASIS'], true)) $this->assertNull($day['gross_delta_minor']);
        }
        $knownBasis = $snapshot;
        $knownBasis['facts'][0]['dimension']['external_dimensions'] = json_encode(['gam_ad_unit_id' => '456', 'gam_report_basis' => 'AD_EXCHANGE_V1']);
        $this->assertFalse($service->compare($context, $knownBasis, $fresh)['days'][0]['basis_change']);
        $snapshot['facts'][] = $snapshot['facts'][0];
        $day = $service->compare($context, $snapshot, $fresh)['days'][0];
        $this->assertNull($day['projected']);
        $this->assertContains('MULTIPLE_STORED_FACTS_NO_ALLOCATION', $day['flags']);
    }
}
