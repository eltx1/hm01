<?php

namespace Tests\Feature;

use App\Enums\{OrganizationType, ReportFinality, ReportGranularity, ReportImportStatus, ReportSourceCode, RoleName};
use App\Models\{DailyReport, ReportSource, ReportSourceConnection, SiteGamReportBinding, SiteGamUnfilledReport};
use App\Services\Reporting\{AdminWebsitePerformanceService, PerformanceMetrics, PublisherPerformanceService, ReportImportService, SiteGamUnfilledProjection, UnifiedReportService};
use Carbon\CarbonImmutable;
use Database\Seeders\{InventoryDeliverySeeder, ReportingSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\{InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites};
use Tests\TestCase;

class SiteGamUnfilledProjectionTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private const DAY = '2034-04-15';
    private const NEXT = '2034-04-16';

    private function context(): array
    {
        $this->travelTo(CarbonImmutable::parse('2034-04-18 12:00:00', 'UTC'));
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, ReportingSeeder::class]);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user);
        $site = $this->makeSiteFor($publisher, $user);
        $gam = $this->makeGamConnection($admin->organization, $admin);
        $connection = ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id,
            'report_source_id' => ReportSource::where('code', ReportSourceCode::HorusGam->value)->firstOrFail()->id,
            'name' => 'Original Unfilled fixture', 'connection_type' => 'TEST', 'connection_id' => 'unfilled-fixture',
            'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'ACTIVE', 'is_enabled' => true,
        ]);

        return compact('admin', 'user', 'publisher', 'site', 'gam', 'connection');
    }

    private function fact(array $context, string $day = self::DAY, array $overrides = [], bool $legacy = false): DailyReport
    {
        $date = CarbonImmutable::parse($day);
        $job = app(ReportImportService::class)->importRows($context['connection'], [[
            'date' => $day, 'site_id' => $context['site']->id, 'publisher_id' => $context['publisher']->id,
            'gam_connection_id' => $context['gam']->id, 'gam_ad_unit_id' => '80412', 'currency' => 'USD',
            'ad_requests' => 120, 'matched_requests' => 100, 'impressions' => 70, 'clicks' => 2,
            'gross_revenue_minor' => 1234, 'unfilled_impressions' => null,
            ...($legacy ? [] : ['gam_report_basis' => 'AD_EXCHANGE_V1',
                'gam_report_site' => $context['site']->primary_domain, 'gam_report_scope' => 'fixture-exact-hostname']),
            ...$overrides,
        ]], ReportGranularity::Daily, ReportFinality::Finalized, $date, $date, $context['admin']);
        $this->assertSame(ReportImportStatus::Completed, $job->status, $job->error_message ?? '');

        return DailyReport::withoutGlobalScopes()->where('report_import_job_id', $job->id)->sole();
    }

    private function binding(array $context, array $overrides = []): SiteGamReportBinding
    {
        $binding = SiteGamReportBinding::withoutGlobalScopes()->create([
            'organization_id' => $context['publisher']->organization_id, 'site_id' => $context['site']->id,
            'gam_connection_id' => $context['gam']->id, 'report_source_connection_id' => $context['connection']->id,
            'network_code' => $context['gam']->network_code, 'ad_unit_id' => '80412',
            'ad_unit_name' => 'Fixture unit', 'ad_unit_code' => 'fixture-unit',
            'starts_on' => self::DAY, 'created_by' => $context['admin']->id, ...$overrides,
        ]);
        $context['connection']->update(['connection_type' => 'SITE_GAM_AD_UNIT', 'connection_id' => $binding->id]);

        return $binding;
    }

    private function sidecar(SiteGamReportBinding $binding, int $value, string $day = self::DAY): SiteGamUnfilledReport
    {
        return SiteGamUnfilledReport::withoutGlobalScopes()->create([
            'organization_id' => $binding->organization_id, 'report_source_connection_id' => $binding->report_source_connection_id,
            'site_gam_report_binding_id' => $binding->id, 'gam_connection_id' => $binding->gam_connection_id,
            'network_code' => $binding->network_code, 'ad_unit_id' => $binding->ad_unit_id,
            'report_date' => $day, 'timezone' => 'UTC', 'unfilled_impressions' => $value,
            'google_report_job_id' => '98765', 'reported_at' => now(),
        ]);
    }

    private function projected(DailyReport ...$rows): ?int
    {
        return app(SiteGamUnfilledProjection::class)->total(collect($rows)->map(fn ($row) => $row->fresh(['dimension', 'connection.source'])));
    }

    public function test_original_unit_unfilled_projects_across_reports_without_changing_financial_facts(): void
    {
        $context = $this->context();
        $first = $this->fact($context);
        $this->fact($context, self::NEXT);
        $binding = $this->binding($context);
        $this->sidecar($binding, 17);
        $this->sidecar($binding, 0, self::NEXT);
        $this->actingAs($context['admin']);
        $before = DB::table('daily_reports')->orderBy('id')->get()->toJson();
        $publisher = app(PublisherPerformanceService::class)->summary($context['publisher'], self::DAY, self::NEXT);
        $this->assertSame(17, $publisher['unfilled_impressions']);
        $this->assertSame([17, 0], $publisher['days']->pluck('unfilled_impressions')->all());
        $this->assertSame([17], $publisher['websites']->pluck('unfilled_impressions')->all());
        $this->assertSame(40, $publisher['ad_exchange_unmatched_requests']);
        $admin = app(UnifiedReportService::class)->adminSummary(self::DAY, self::NEXT);
        $this->assertSame(17, $admin['performance']['unfilled_impressions']);
        foreach (['revenue_by_publisher', 'revenue_by_website', 'revenue_by_source', 'revenue_by_campaign'] as $key) {
            $this->assertSame([17], $admin[$key]->pluck('unfilled_impressions')->all());
        }
        $service = app(AdminWebsitePerformanceService::class);
        $website = $service->summary($context['site'], self::DAY, self::NEXT);
        $this->assertSame(17, $website['unfilled_impressions']);
        $this->assertSame([17, 0], $website['days']->pluck('unfilled_impressions')->all());
        $this->assertSame(17, $service->summaries(collect([$context['site']]), self::DAY, self::NEXT)[$context['site']->id]['unfilled_impressions']);
        $this->assertSame(2468, $website['gross_revenue_minor']);
        $this->assertSame($before, DB::table('daily_reports')->orderBy('id')->get()->toJson());
        $this->assertNull($first->fresh()->unfilled_impressions);
    }

    public function test_missing_unit_day_stays_null_and_true_zero_is_not_missing(): void
    {
        $context = $this->context();
        $first = $this->fact($context);
        $second = $this->fact($context, self::NEXT);
        $binding = $this->binding($context);
        $this->sidecar($binding, 0);
        $this->assertSame(0, $this->projected($first));
        $this->assertNull($this->projected($first, $second));
        $this->assertNull(app(SiteGamUnfilledProjection::class)->total(collect()));
        $this->actingAs($context['admin']);
        $summary = app(AdminWebsitePerformanceService::class)->summary($context['site'], self::DAY, self::NEXT);
        $this->assertNull($summary['unfilled_impressions']);
        $this->assertSame([0, null], $summary['days']->pluck('unfilled_impressions')->all());
    }

    public function test_multiple_financial_dimensions_do_not_duplicate_the_unit_total(): void
    {
        $context = $this->context();
        $first = $this->fact($context, overrides: ['device' => 'desktop']);
        $second = $this->fact($context, overrides: ['device' => 'mobile']);
        $binding = $this->binding($context);
        $this->sidecar($binding, 31);
        $this->assertSame(31, $this->projected($first, $second));
        $this->actingAs($context['admin']);
        $summary = app(AdminWebsitePerformanceService::class)->summary($context['site'], self::DAY, self::DAY);
        $this->assertSame(31, $summary['unfilled_impressions']);
        $this->assertSame(140, $summary['impressions']);
        $this->assertSame(2468, $summary['gross_revenue_minor']);
    }

    public function test_original_unit_metric_is_independent_of_adx_provenance_and_current_hostname(): void
    {
        $context = $this->context();
        $row = $this->fact($context, legacy: true);
        $binding = $this->binding($context);
        $this->sidecar($binding, 29);
        $context['site']->update(['primary_domain' => 'new-hostname.example']);
        $summary = app(PerformanceMetrics::class)->summarize(collect([$row->fresh(['dimension', 'connection.source'])]), 'gross_revenue_minor');
        $this->assertTrue($summary['metric_basis_incomplete']);
        $this->assertNull($summary['impressions']);
        $this->assertNull($summary['ad_exchange_unmatched_requests']);
        $this->assertSame(29, $summary['unfilled_impressions']);
        $this->actingAs($context['admin']);
        $this->assertSame(29, app(AdminWebsitePerformanceService::class)->summary($context['site'], self::DAY, self::DAY)['unfilled_impressions']);
    }

    public function test_preexisting_original_counter_requires_a_verified_legacy_binding(): void
    {
        $context = $this->context();
        $legacy = $this->fact($context, overrides: ['unfilled_impressions' => 19], legacy: true);
        $adx = $this->fact($context, self::NEXT, ['unfilled_impressions' => 999]);
        $context['connection']->update(['connection_type' => 'SITE_GAM_AD_UNIT']);
        $this->assertNull($this->projected($legacy));
        $binding = $this->binding($context);
        $this->assertSame(19, $this->projected($legacy));
        $this->assertNull($this->projected($adx));
        $this->actingAs($context['admin']);
        $this->assertSame(19, app(AdminWebsitePerformanceService::class)->summary($context['site'], self::DAY, self::DAY)['unfilled_impressions']);
        $binding->update(['starts_on' => self::NEXT]);
        $this->assertNull($this->projected($legacy));
    }

    public function test_stopped_historical_binding_remains_readable_only_for_its_owned_dates(): void
    {
        $context = $this->context();
        $old = $this->fact($context);
        $outside = $this->fact($context, self::NEXT);
        $binding = $this->binding($context, ['ends_on' => self::DAY]);
        $this->sidecar($binding, 41);
        $this->sidecar($binding, 999, self::NEXT);
        $context['connection']->update(['is_enabled' => false, 'status' => 'DISABLED']);
        $this->assertSame(41, $this->projected($old));
        $this->assertNull($this->projected($outside));
        $context['connection']->update(['connection_id' => 'wrong-binding']);
        $this->assertNull($this->projected($old));
    }

    public function test_sidecar_provenance_must_match_and_bad_sidecar_never_falls_back_to_legacy(): void
    {
        $context = $this->context();
        $row = $this->fact($context, overrides: ['unfilled_impressions' => 12], legacy: true);
        $binding = $this->binding($context);
        $report = $this->sidecar($binding, 17);
        foreach (['network_code' => 'other-network', 'ad_unit_id' => '99999', 'timezone' => 'Africa/Cairo'] as $field => $wrong) {
            $original = $report->$field;
            $report->update([$field => $wrong]);
            $this->assertNull($this->projected($row), $field);
            $this->actingAs($context['admin']);
            $this->assertNull(app(AdminWebsitePerformanceService::class)->summary($context['site'], self::DAY, self::DAY)['unfilled_impressions'], $field);
            $report->update([$field => $original]);
        }
        $otherGam = $this->makeGamConnection($context['admin']->organization, $context['admin']);
        $report->update(['gam_connection_id' => $otherGam->id]);
        $this->assertNull($this->projected($row));
        $report->update(['gam_connection_id' => $binding->gam_connection_id]);
        $dimension = $row->dimension;
        $external = $dimension->external_dimensions;
        $dimension->update(['external_dimensions' => [...$external, 'gam_ad_unit_id' => '99999']]);
        $this->assertNull($this->projected($row));
    }

    public function test_source_original_counters_remain_compatible_and_mixed_missing_values_stay_null(): void
    {
        $context = $this->context();
        $row = $this->fact($context, overrides: ['unfilled_impressions' => 9]);
        $this->assertSame(9, $this->projected($row));
        $binding = $this->binding($context);
        $this->sidecar($binding, 17);
        $unit = $row->fresh(['dimension', 'connection.source']);
        $other = ['unfilled_impressions' => 9, 'connection' => ['connection_type' => 'OTHER']];
        $this->assertSame(26, app(SiteGamUnfilledProjection::class)->total(collect([$unit, $other])));
        $other['unfilled_impressions'] = null;
        $this->assertNull(app(SiteGamUnfilledProjection::class)->total(collect([$unit, $other])));
    }

    public function test_group_projections_reuse_batched_results_without_persistent_writes(): void
    {
        $context = $this->context();
        $first = $this->fact($context);
        $second = $this->fact($context, self::NEXT);
        $binding = $this->binding($context);
        $this->sidecar($binding, 17);
        $this->sidecar($binding, 23, self::NEXT);
        $rows = collect([$first->fresh(['dimension', 'connection.source']), $second->fresh(['dimension', 'connection.source'])]);
        $this->assertSame(40, app(SiteGamUnfilledProjection::class)->total($rows));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame(17, app(SiteGamUnfilledProjection::class)->total($rows->take(1)));
        $this->assertSame(23, app(SiteGamUnfilledProjection::class)->total($rows->skip(1)));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertFalse($rows[0]->isDirty());
    }

    public function test_duplicate_credentials_for_one_network_unit_day_are_counted_once_and_disagreements_are_unknown(): void
    {
        $context = $this->context();
        $other = $context;
        $other['gam'] = $this->makeGamConnection($context['admin']->organization, $context['admin'], ['network_code' => $context['gam']->network_code]);
        $other['connection'] = $context['connection']->replicate();
        $other['connection']->connection_id = 'another-credential-fixture';
        $other['connection']->save();
        $first = $this->fact($context);
        $second = $this->fact($other);
        $firstBinding = $this->binding($context);
        $secondBinding = $this->binding($other);
        $this->sidecar($firstBinding, 31);
        $otherReport = $this->sidecar($secondBinding, 31);
        $this->assertSame(31, $this->projected($first, $second));
        $this->actingAs($context['admin']);
        $service = app(AdminWebsitePerformanceService::class);
        $this->assertSame(31, $service->summary($context['site'], self::DAY, self::DAY)['unfilled_impressions']);
        $this->assertSame(2468, $service->summary($context['site'], self::DAY, self::DAY)['gross_revenue_minor']);
        $otherReport->update(['unfilled_impressions' => 32]);
        $this->assertNull($this->projected($first, $second));
        $this->assertNull($service->summary($context['site'], self::DAY, self::DAY)['unfilled_impressions']);
    }

    public function test_publisher_cannot_project_another_tenants_unit_counter(): void
    {
        $context = $this->context();
        $other = $context;
        $other['user'] = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $other['publisher'] = $this->makePublisherFor($other['user']);
        $other['site'] = $this->makeSiteFor($other['publisher'], $other['user']);
        $other['connection'] = $context['connection']->replicate();
        $other['connection']->organization_id = $other['publisher']->organization_id;
        $other['connection']->connection_id = 'other-tenant-fixture';
        $other['connection']->save();
        $first = $this->fact($context);
        $this->fact($other);
        $this->sidecar($this->binding($context), 17);
        $this->sidecar($this->binding($other), 999);
        $this->actingAs($context['user']);
        $summary = app(PublisherPerformanceService::class)->summary($context['publisher'], self::DAY, self::DAY);
        $this->assertSame(17, $summary['unfilled_impressions']);
        $this->assertSame(1, $summary['websites']->count());
        $this->actingAs($context['admin']);
        $first->dimension()->update(['organization_id' => $other['publisher']->organization_id]);
        $this->assertNull($this->projected($first));
        $this->assertNull(app(AdminWebsitePerformanceService::class)->summary($context['site'], self::DAY, self::DAY)['unfilled_impressions']);
    }

    public function test_scope_labels_follow_original_unit_totals_without_relabeling_other_sources(): void
    {
        $this->assertSame('Unfilled impressions', PerformanceMetrics::label('unfilled_impressions'));
        $this->assertSame('Unfilled impressions (ad unit, all sites)', PerformanceMetrics::label('unfilled_impressions', ['has_site_ad_exchange' => true]));
        $this->assertSame('Impressions', PerformanceMetrics::label('impressions', ['has_site_ad_exchange' => true]));
        $context = $this->context();
        $row = $this->fact($context);
        $this->sidecar($this->binding($context), 17);
        $summary = app(PerformanceMetrics::class)->summarize(collect([$row->fresh(['dimension', 'connection.source'])]), 'gross_revenue_minor');
        $this->assertSame('AD_UNIT_ALL_SITES_V1', $summary['unfilled_scope']);
    }
}
