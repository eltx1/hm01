<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\ReportFinality;
use App\Enums\FinancialReportingMethod;
use App\Enums\ReportGranularity;
use App\Enums\ReportImportStatus;
use App\Enums\ReportSourceCode;
use App\Enums\RoleName;
use App\Models\ConfigVersion;
use App\Models\DailyReport;
use App\Models\DemandAccount;
use App\Models\DemandNetwork;
use App\Models\DemandSite;
use App\Models\GamApiOperation;
use App\Models\GamConnection;
use App\Models\HourlyReport;
use App\Models\ReportImportJob;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Models\SiteGamReportBinding;
use App\Services\Gam\Contracts\GamSoapTransportInterface;
use App\Services\Gam\GamSoapPayloadHydrator;
use App\Services\Gam\GamSoapVersionResolver;
use App\Services\Monetization\ReportingHealthService;
use App\Services\Reporting\Connectors\GamAdUnitReportConnector;
use App\Services\Reporting\FinancialPeriodService;
use App\Services\Reporting\MonetizationFinancialBindingService;
use App\Services\Reporting\MonetizationFinancialReadinessService;
use App\Services\Reporting\ReportImportService;
use App\Services\Reporting\ReportingBridge;
use App\Services\Reporting\RevenueRuleService;
use App\Services\Reporting\SiteGamFinancialCoverage;
use App\Services\Reporting\SiteGamReportingService;
use App\Services\Reporting\SiteGamReportSynchronizer;
use App\Services\Reporting\SiteGamTodayReport;
use App\Services\Reporting\UnifiedReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Database\Seeders\ReportingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class SiteGamReportingTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private object $google;

    private function context(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 10:00:00', 'UTC'));
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, ReportingSeeder::class]);
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $org = $this->makeOrganization(OrganizationType::Publisher, 'Publisher');
        $user = $this->makeUser($org, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user);
        $site = $this->makeSiteFor($publisher, $user);
        $gam = $this->makeGamConnection($horus, $admin);
        $this->google = new class implements GamSoapTransportInterface
        {
            public array $calls = [];

            public array $units = [['id' => '12345', 'name' => 'Publisher unit', 'adUnitCode' => 'publisher_unit']];

            public string $status = 'COMPLETED';

            public string $url = 'https://storage.googleapis.com/report.csv?signature=private-download';

            public string $timezone = 'Africa/Cairo';

            public string $currency = 'USD';

            public int $jobs = 0;

            public function call(GamConnection $connection, string $service, string $method, array $payload = []): array
            {
                $this->calls[] = compact('service', 'method', 'payload');
                // Exercise the generated SDK objects too, not just our fake responses.
                $versions = app(GamSoapVersionResolver::class);
                $namespace = $versions->namespaceFor($versions->resolve());
                $reflection = new \ReflectionClass($namespace.'\\'.$service);
                app(GamSoapPayloadHydrator::class)->arguments($reflection->newInstanceWithoutConstructor(), $method, $payload, $namespace);

                if ($method === 'runReportJob' && in_array('HOUR', $payload['reportJob']['reportQuery']['dimensions'], true)) {
                    throw new \RuntimeException('ReportError.COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS');
                }

                return match ($method) {
                    'getCurrentNetwork' => ['networkCode' => $connection->network_code, 'currencyCode' => $this->currency, 'timeZone' => $this->timezone],
                    'getAdUnitsByStatement' => ['results' => $this->units],
                    'runReportJob' => ['id' => (string) ++$this->jobs],
                    'getReportJobStatus' => ['value' => $this->status],
                    'getReportDownloadUrlWithOptions' => ['value' => $this->url],
                    default => throw new \RuntimeException('Unexpected Google call: '.$method),
                };
            }
        };
        $this->app->instance(GamSoapTransportInterface::class, $this->google);
        Http::preventStrayRequests();

        return [$admin, $publisher, $user, $site, $gam];
    }

    private function bind(array $context): SiteGamReportBinding
    {
        return app(SiteGamReportingService::class)->bind($context[3], $context[4]->id, 'Publisher unit', $context[0]);
    }

    private function csv(array $rows = [['2026-09-20', '12345', 120, 100, 20, 95, 3, 'US$ 123450000']], bool $hourly = false): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, ['Dimension.DATE', ...($hourly ? ['Dimension.HOUR'] : []), 'Dimension.AD_UNIT_ID',
            ...array_map(fn ($column) => 'Column.'.$column, array_keys(GamAdUnitReportConnector::COLUMNS))], escape: '');
        foreach ($rows as $row) {
            fputcsv($stream, $row, escape: '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    private function import(SiteGamReportBinding $binding, string $from = '2026-09-20', string $to = '2026-09-20')
    {
        return app(ReportImportService::class)->runConnection($binding->connection, CarbonImmutable::parse($from),
            CarbonImmutable::parse($to), ReportGranularity::Daily, ReportFinality::Finalized);
    }

    public function test_site_page_shows_current_estimates_without_changing_finalized_totals_or_calling_google(): void
    {
        $context = [$admin, , $publisherUser, $site] = $this->context();
        $binding = $this->bind($context);
        Http::fake(['storage.googleapis.com/*' => Http::sequence()->push($this->csv())
            ->push($this->csv([['2026-09-21', '12345', 180, 150, 30, 125, 5, 456780000]]))]);
        $this->assertSame(ReportImportStatus::Completed, $this->import($binding)->status);
        $day = CarbonImmutable::parse('2026-09-21', 'Africa/Cairo');
        $job = app(ReportImportService::class)->runConnection($binding->connection->fresh(), $day, $day->endOfDay(), ReportGranularity::Daily, ReportFinality::Estimated);
        $this->assertSame(ReportImportStatus::Completed, $job->status);
        $calls = count($this->google->calls);
        $count = ReportImportJob::count();

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.sites.show', $site))->assertOk()
            ->assertSee('Today so far')->assertSee('456.78 USD')->assertSee('Last imported: 2026-09-21 13:00:00')
            ->assertSee('View completed-day reports')
            ->assertViewHas('todayReport', fn ($report) => $report['available'] && $report['impressions'] === 125 && $report['clicks'] === 5);
        $this->assertSame($calls, count($this->google->calls));
        $this->assertSame($count, ReportImportJob::count());
        $this->assertFalse($job->settlement_eligible);
        $this->assertSame(12345, app(UnifiedReportService::class)->adminSummary('2026-09-01', '2026-09-21', 'USD')['gross_revenue_minor']);
        $this->actingAs($publisherUser)->get(route('admin.sites.show', $site))->assertForbidden();
    }

    public function test_today_report_uses_network_midnight_and_distinguishes_pending_data_from_a_real_zero(): void
    {
        $context = [$admin, , , $site] = $this->context();
        $binding = $this->bind($context);
        $day = CarbonImmutable::parse('2026-09-21', 'Africa/Cairo');
        Http::fake(['storage.googleapis.com/*' => Http::sequence()
            ->push($this->csv([['2026-09-21', '12345', 180, 150, 30, 125, 5, 456780000]]))->push($this->csv([]))]);
        app(ReportImportService::class)->runConnection($binding->connection->fresh(), $day, $day->endOfDay(), ReportGranularity::Daily, ReportFinality::Estimated);
        // UTC is still September 21, but this network's new day has started.
        $this->travelTo(CarbonImmutable::parse('2026-09-21 22:30:00', 'UTC'));
        $report = app(SiteGamTodayReport::class)->forSite($site->fresh());
        $this->assertSame('2026-09-22', $report['date']);
        $this->assertFalse($report['available']);
        $this->assertNull($report['updated_at']);
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.sites.show', $site))->assertOk()->assertSee("Today's report has not arrived yet.", false)->assertDontSee('456.78 USD');

        $nextDay = $day->addDay();
        $job = app(ReportImportService::class)->runConnection($binding->connection->fresh(), $nextDay, $nextDay->endOfDay(), ReportGranularity::Daily, ReportFinality::Estimated);
        $this->assertSame(ReportImportStatus::Completed, $job->status);
        $report = app(SiteGamTodayReport::class)->forSite($site->fresh());
        $this->assertTrue($report['available']);
        $this->assertSame(0, $report['gross_revenue_minor']);
        $this->assertSame('2026-09-22 01:30:00', $report['updated_at']);
        $this->get(route('admin.sites.show', $site))->assertOk()->assertSee('0.00 USD')->assertDontSee('report has not arrived yet');
    }

    public function test_today_report_is_scoped_to_the_bound_website_and_requires_reporting_permission(): void
    {
        $context = [$admin, $publisher, $publisherUser, $site] = $this->context();
        $binding = $this->bind($context);
        $day = CarbonImmutable::parse('2026-09-21', 'Africa/Cairo');
        Http::fake(['storage.googleapis.com/*' => Http::response($this->csv([['2026-09-21', '12345', 180, 150, 30, 125, 5, 456780000]]))]);
        app(ReportImportService::class)->runConnection($binding->connection->fresh(), $day, $day->endOfDay(), ReportGranularity::Daily, ReportFinality::Estimated);
        $otherSite = $this->makeSiteFor($publisher, $publisherUser);
        $this->assertNull(app(SiteGamTodayReport::class)->forSite($otherSite));
        $row = DailyReport::withoutGlobalScopes()->sole();
        $row->dimension->update(['site_id' => $otherSite->id]);
        $this->assertFalse(app(SiteGamTodayReport::class)->forSite($site->fresh())['available']);
        $row->dimension->update(['site_id' => $site->id]);

        $support = $this->makeUser($admin->organization, RoleName::SupportAgent);
        $this->actingAs($support)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.sites.show', $site))->assertOk()->assertViewHas('todayReport', null)->assertDontSee('456.78 USD');
        $binding->connection->update(['organization_id' => $admin->organization_id]);
        $this->assertNull(app(SiteGamTodayReport::class)->forSite($site->fresh()));
    }

    public function test_admin_connects_in_one_submission_with_no_serving_changes_and_safe_search(): void
    {
        $context = [$admin, , $user, $site, $gam] = $this->context();
        $before = $site->fresh()->getAttributes();
        $configs = ConfigVersion::query()->count();
        $this->google->currency = 'AED';
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->post(route('admin.sites.reporting.gam.store', $site), [
            'gam_connection_id' => $gam->id, 'ad_unit' => 'Publisher unit',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.sites.show', $site).'#reporting');
        $binding = SiteGamReportBinding::withoutGlobalScopes()->sole();
        $this->assertSame('2026-09-01', $binding->starts_on->toDateString());
        $this->assertSame('Africa/Cairo', $binding->connection->timezone);
        $this->assertSame('USD', $binding->connection->currency);
        $this->assertSame('USD', data_get($binding->connection->configuration, 'report_currency'));
        $this->assertSame('AED', data_get($binding->connection->configuration, 'source_network_currency'));
        $this->assertSame($site->organization_id, $binding->connection->organization_id);
        $this->assertSame($before, $site->fresh()->getAttributes());
        $this->assertSame($configs, ConfigVersion::query()->count());
        $this->assertSame($binding->id, $this->bind($context)->id);
        $this->actingAs($admin)->get(route('admin.sites.show', $site))->assertOk()->assertSee('Connect an Ad Manager ad unit')->assertSee('data-gam-report-binding', false);
        $query = "unit' OR id > 0";
        $this->getJson(route('admin.sites.reporting.gam.units', $site).'?'.http_build_query(['gam_connection_id' => $gam->id, 'q' => $query]))
            ->assertOk()->assertJsonPath('units.0.id', '12345');
        $call = end($this->google->calls);
        $this->assertStringNotContainsString($query, $call['payload']['filterStatement']['query']);
        $this->assertSame('%'.$query.'%', $call['payload']['filterStatement']['values'][0]['value']['value']);
        $this->actingAs($user)->post(route('admin.sites.reporting.gam.store', $site), ['gam_connection_id' => $gam->id, 'ad_unit' => '12345'])->assertForbidden();
        $this->getJson(route('admin.sites.reporting.gam.units', $site).'?gam_connection_id='.$gam->id)->assertForbidden();
    }

    public function test_existing_open_legacy_site_gam_connection_is_normalized_to_usd_without_replacing_binding(): void
    {
        [$admin] = $context = $this->context();
        $binding = $this->bind($context);
        $configuration = $binding->connection->configuration ?? [];
        $configuration['google_jobs'] = ['stale' => ['id' => '77', 'requested_at' => now()->toIso8601String()]];
        $configuration['sync_due'] = ['stale' => now()->addHour()->toIso8601String()];
        $binding->connection->update(['currency' => 'AED', 'configuration' => $configuration]);
        $this->google->currency = 'AED';

        $same = app(SiteGamReportingService::class)->bind($context[3], $context[4]->id, '12345', $admin);

        $this->assertSame($binding->id, $same->id);
        $this->assertSame('USD', $same->connection->currency);
        $this->assertSame('AED', data_get($same->connection->configuration, 'source_network_currency'));
        $this->assertSame('USD', data_get($same->connection->configuration, 'report_currency'));
        $this->assertArrayNotHasKey('google_jobs', $same->connection->configuration);
        $this->assertArrayNotHasKey('sync_due', $same->connection->configuration);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'reporting.site_gam.currency_normalized',
            'auditable_id' => $binding->id,
        ]);
    }

    public function test_ambiguous_unit_and_foreign_publisher_account_are_rejected_without_creating_a_binding(): void
    {
        [$admin, , $user, $site] = $context = $this->context();
        $other = $this->makeOrganization(OrganizationType::Publisher, 'Other');
        $foreign = $this->makeGamConnection($other, $admin);
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->post(route('admin.sites.reporting.gam.store', $site), ['gam_connection_id' => $foreign->id, 'ad_unit' => '12345'])->assertSessionHasErrors('gam_connection_id');
        $this->google->units[] = ['id' => '67890', 'name' => 'Publisher unit', 'adUnitCode' => 'other'];
        $this->post(route('admin.sites.reporting.gam.store', $site), ['gam_connection_id' => $context[4]->id, 'ad_unit' => 'Publisher unit'])->assertSessionHasErrors('ad_unit');
        $this->assertDatabaseCount('site_gam_report_bindings', 0);
    }

    public function test_same_physical_unit_cannot_be_assigned_to_two_websites_even_with_duplicate_connections(): void
    {
        [$admin, $publisher, $user, , $gam] = $context = $this->context();
        $this->bind($context);
        $second = $this->makeSiteFor($publisher, $user);
        $duplicate = $this->makeGamConnection($admin->organization, $admin, ['network_code' => $gam->network_code]);
        $this->expectException(ValidationException::class);
        app(SiteGamReportingService::class)->bind($second, $duplicate->id, '12345', $admin);
    }

    public function test_real_csv_units_currency_rules_and_publisher_reports_use_the_existing_financial_pipeline(): void
    {
        [$admin, $publisher, , $site] = $context = $this->context();
        $this->google->currency = 'AED';
        $binding = $this->bind($context);
        app(RevenueRuleService::class)->createRule(['name' => 'Website split', 'scope_type' => 'WEBSITE', 'scope_id' => $site->id,
            'effective_from' => '2026-09-01', 'publisher_share_bp' => 8000, 'horus_share_bp' => 2000, 'mcm_partner_share_bp' => 0], $admin);
        Http::fake(['storage.googleapis.com/*' => fn () => Http::response($this->csv())]);
        $job = $this->import($binding);
        $this->assertSame(ReportImportStatus::Completed, $job->status, $job->error_message ?? '');
        $row = DailyReport::withoutGlobalScopes()->sole();
        $this->assertSame(12345, (int) $row->gross_revenue_minor);
        $this->assertSame(9876, (int) $row->publisher_earnings_minor);
        $this->assertTrue($row->settlement_eligible);
        $this->assertSame($site->organization_id, $row->organization_id);
        $this->assertSame($site->id, $row->dimension->site_id);
        $this->assertSame('MATCHED', $job->reconciliations()->sole()->status->value);
        $summary = app(UnifiedReportService::class)->publisherSummary($publisher, CarbonImmutable::parse('2026-09-20'), CarbonImmutable::parse('2026-09-20'));
        $this->assertSame(9876, $summary['revenue_minor']);
        $query = collect($this->google->calls)->firstWhere('method', 'runReportJob')['payload']['reportJob']['reportQuery'];
        $this->assertSame('FLAT', $query['adUnitView']);
        $this->assertSame('12345', $query['statement']['values'][0]['value']['value']);
        $this->assertSame('USD', $query['reportCurrency']);
        $this->assertSame('USD', $row->currency);
        $this->assertContains('TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE', $query['columns']);
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.reporting.index', ['currency' => 'AED']))
            ->assertOk()
            ->assertViewHas('summary', fn (array $summary): bool => $summary['currency'] === 'USD')
            ->assertSee('Horus requests GAM revenue from Google in USD');
        $this->assertStringNotContainsString('private-download', GamApiOperation::query()->get()->toJson());
    }

    public function test_pending_google_job_is_resumed_without_duplicate_submission_or_false_zero_revenue(): void
    {
        $binding = $this->bind($this->context());
        $this->google->status = 'IN_PROGRESS';
        $pending = $this->import($binding);
        $this->assertSame(ReportImportStatus::Pending, $pending->status);
        $this->import($binding);
        $this->assertSame(1, $this->google->jobs);
        $this->assertDatabaseCount('daily_reports', 0);
        $this->google->status = 'COMPLETED';
        Http::fake(['storage.googleapis.com/*' => fn () => Http::response($this->csv())]);
        $this->assertSame(ReportImportStatus::Completed, $this->import($binding)->status);
        $this->assertSame(ReportImportStatus::Duplicate, $pending->fresh()->status);
        $this->assertSame(1, $this->google->jobs);
        $this->assertEmpty($binding->connection->fresh()->configuration['google_jobs']);
    }

    public function test_repeat_and_corrected_empty_snapshots_replace_rows_instead_of_adding_revenue(): void
    {
        $binding = $this->bind($this->context());
        Http::fake(['storage.googleapis.com/*' => Http::sequence()->push($this->csv())->push($this->csv())->push($this->csv([]))]);
        $this->import($binding, '2026-09-19', '2026-09-20');
        $again = $this->import($binding, '2026-09-19', '2026-09-20');
        $this->assertSame(ReportImportStatus::Completed, $again->status, json_encode($again->toArray()));
        $this->assertSame(2, $again->duplicate_count, json_encode($again->toArray()));
        $this->assertSame(12345, (int) DailyReport::withoutGlobalScopes()->sum('gross_revenue_minor'));
        $corrected = $this->import($binding, '2026-09-19', '2026-09-20');
        $this->assertSame(ReportImportStatus::Completed, $corrected->status);
        $this->assertDatabaseCount('daily_reports', 2);
        $this->assertSame(0, (int) DailyReport::withoutGlobalScopes()->sum('gross_revenue_minor'));
    }

    public function test_wrong_unit_and_malformed_downloads_fail_atomically_and_do_not_become_zero_reports(): void
    {
        $binding = $this->bind($this->context());
        Http::fake(['storage.googleapis.com/*' => fn () => Http::response($this->csv([['2026-09-20', '999', 1, 1, 0, 1, 0, 10000]]))]);
        $job = $this->import($binding);
        $this->assertSame(ReportImportStatus::Failed, $job->status);
        $this->assertDatabaseCount('daily_reports', 0);
        Http::fake(['storage.googleapis.com/*' => fn () => Http::response('<html>Error</html>')]);
        $this->assertSame(ReportImportStatus::Failed, $this->import($binding)->status);
        $this->assertDatabaseCount('daily_reports', 0);
    }

    public function test_download_url_is_restricted_to_google_without_exposing_temporary_credentials(): void
    {
        $binding = $this->bind($this->context());
        $this->google->url = 'https://attacker.invalid/report?signature=private-download';
        $job = $this->import($binding);
        $this->assertSame(ReportImportStatus::Failed, $job->status);
        Http::assertNothingSent();
        $this->assertStringNotContainsString('private-download', $job->error_message);
        $this->assertDatabaseCount('daily_reports', 0);
    }

    public function test_csv_overlap_is_excluded_only_for_this_site_and_previous_days_are_preserved(): void
    {
        [$admin, $publisher, $user, $site] = $context = $this->context();
        $csv = ReportSourceConnection::withoutGlobalScopes()->create(['organization_id' => $publisher->organization_id,
            'report_source_id' => ReportSource::where('code', ReportSourceCode::CustomCsv->value)->value('id'), 'name' => 'Existing CSV',
            'connection_type' => 'CSV', 'currency' => 'USD', 'timezone' => 'UTC', 'is_enabled' => true, 'status' => 'ACTIVE']);
        $imports = app(ReportImportService::class);
        $oldDay = CarbonImmutable::parse('2026-09-10');
        $imports->importRows($csv, [['date' => '2026-09-10', 'site_id' => $site->id, 'gross_revenue_minor' => 1000]], ReportGranularity::Daily, ReportFinality::Finalized, $oldDay, $oldDay, $admin, importType: 'CSV');
        $binding = $this->bind($context);
        $this->assertSame('2026-09-11', $binding->starts_on->toDateString());
        $otherSite = $this->makeSiteFor($publisher, $user);
        $day = CarbonImmutable::parse('2026-09-20');
        $job = $imports->importRows($csv, [
            ['date' => '2026-09-20', 'site_id' => $site->id, 'gross_revenue_minor' => 2000],
            ['date' => '2026-09-20', 'site_id' => $otherSite->id, 'gross_revenue_minor' => 3000],
        ], ReportGranularity::Daily, ReportFinality::Finalized, $day, $day, $admin, importType: 'CSV', sourceTotals: ['gross_revenue_minor' => 5000]);
        $this->assertSame(ReportImportStatus::Completed, $job->status);
        $this->assertSame(1, $job->row_count);
        $this->assertSame('MATCHED', $job->reconciliations()->sole()->status->value);
        $this->assertSame(4000, (int) DailyReport::withoutGlobalScopes()->sum('gross_revenue_minor'));
        $this->assertSame('SITE_REPORTING_SOURCE_EXCLUDED_ROWS', $job->warnings[0]['code']);
        $this->expectException(ValidationException::class);
        $imports->importRows($binding->connection, [], ReportGranularity::Daily, ReportFinality::Finalized, $day, $day, $admin, importType: 'CSV');
    }

    public function test_complete_month_reaches_financial_close_and_incomplete_month_blocks_close(): void
    {
        [$admin] = $context = $this->context();
        $binding = $this->bind($context);
        $period = app(FinancialPeriodService::class)->periodFor('2026-09-20', 'USD');
        $this->assertCount(1, app(SiteGamFinancialCoverage::class)->blockers($period));
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00'));
        Http::fake(['storage.googleapis.com/*' => fn () => Http::response($this->csv())]);
        $job = $this->import($binding, '2026-09-01', '2026-09-30');
        $this->assertSame(ReportImportStatus::Completed, $job->status, $job->error_message ?? '');
        $this->assertCount(0, app(SiteGamFinancialCoverage::class)->blockers($period));
        $readiness = app(FinancialPeriodService::class)->readiness($period);
        $this->assertTrue($readiness['ready'], json_encode($readiness['blockers']));
        app(FinancialPeriodService::class)->close($period, $admin);
        $this->assertSame('CLOSED', $period->fresh()->status->value);
        $this->assertDatabaseCount('publisher_statements', 1);
        $blocked = $this->import($binding);
        $this->assertSame(ReportImportStatus::BlockedClosedPeriod, $blocked->status, $blocked->error_message ?? '');
        $this->assertSame(12345, (int) DailyReport::withoutGlobalScopes()->sum('gross_revenue_minor'));
    }

    public function test_scheduler_refreshes_daily_estimates_hourly_without_incompatible_hour_dimensions(): void
    {
        $context = $this->context();
        $this->google->timezone = 'Asia/Tokyo';
        $this->travelTo(CarbonImmutable::parse('2026-09-20 16:00:00', 'UTC'));
        $binding = $this->bind($context);
        $this->google->status = 'IN_PROGRESS';
        app(SiteGamReportSynchronizer::class)->sync($binding);
        $queries = collect($this->google->calls)->where('method', 'runReportJob')->values();
        $this->assertCount(2, $queries);
        $this->assertSame(20, $queries[0]['payload']['reportJob']['reportQuery']['endDate']['day']);
        $this->assertSame(21, $queries[1]['payload']['reportJob']['reportQuery']['startDate']['day']);
        $this->assertSame(['DATE', 'AD_UNIT_ID'], $queries[1]['payload']['reportJob']['reportQuery']['dimensions']);
        $this->assertSame(array_keys(GamAdUnitReportConnector::COLUMNS), $queries[1]['payload']['reportJob']['reportQuery']['columns']);
        $this->travel(5)->minutes();
        app(SiteGamReportSynchronizer::class)->sync($binding->fresh());
        $this->assertSame(2, $this->google->jobs);
        $this->assertDatabaseCount('hourly_reports', 0);
        $this->assertDatabaseCount('daily_reports', 0);
        $this->google->status = 'COMPLETED';
        Http::fake(['storage.googleapis.com/*' => function () {
            $id = collect($this->google->calls)->where('method', 'getReportDownloadUrlWithOptions')->last()['payload']['reportJobId'];

            return Http::response($id === '2'
                ? $this->csv([['2026-09-21', '12345', 10, 9, 1, 8, 1, 1250000]]) : $this->csv());
        }]);
        $this->travel(5)->minutes();
        app(SiteGamReportSynchronizer::class)->sync($binding->fresh());
        $this->assertSame(2, $this->google->jobs);
        $this->assertSame(21, DailyReport::withoutGlobalScopes()->count());
        $this->assertSame(0, HourlyReport::withoutGlobalScopes()->count());
        $today = DailyReport::withoutGlobalScopes()->whereDate('report_date', '2026-09-21')->sole();
        $this->assertSame(125, (int) $today->gross_revenue_minor);
        $this->assertFalse((bool) $today->settlement_eligible);
        $this->assertSame('ESTIMATED', $today->finality->value);
        $this->assertSame(0, $binding->connection->imports()->where('status', 'PENDING')->count());
        $before = count($this->google->calls);
        app(SiteGamReportSynchronizer::class)->sync($binding->fresh());
        $this->assertSame($before, count($this->google->calls));
    }

    public function test_legacy_hourly_retry_becomes_one_daily_snapshot_and_keeps_all_metrics_and_financial_finality(): void
    {
        $binding = $this->bind($this->context());
        $day = CarbonImmutable::parse('2026-09-21', 'Africa/Cairo');
        $legacy = ReportImportJob::withoutGlobalScopes()->create([
            'organization_id' => $binding->organization_id, 'report_source_connection_id' => $binding->connection->id,
            'import_type' => 'API', 'granularity' => 'HOURLY', 'finality' => 'ESTIMATED', 'status' => 'FAILED',
            'period_start' => $day, 'period_end' => $day->endOfDay(), 'idempotency_key' => hash('sha256', 'legacy-hourly'),
            'error_message' => 'ReportError.COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS', 'next_retry_at' => now()->subMinute(),
        ]);
        $legacyKey = hash('sha256', 'HOURLY|2026-09-21|2026-09-21');
        $binding->connection->update(['status' => 'ERROR', 'last_error' => $legacy->error_message,
            'configuration' => ['google_jobs' => [$legacyKey => ['id' => '999', 'requested_at' => now()->toIso8601String()]]]]);
        Http::fake(['storage.googleapis.com/*' => Http::sequence()
            ->push($this->csv([['2026-09-21', '12345', 120, 100, 20, 95, 3, 123450000]]))
            ->push($this->csv([['2026-09-21', '12345', 130, 108, 22, 101, 4, 135000000]]))
            ->push($this->csv([['2026-09-21', '12345', 130, 108, 22, 101, 4, 140000000]]))]);
        $imports = app(ReportImportService::class);
        $run = fn () => $imports->runConnection($binding->connection->fresh(), $day, $day->endOfDay(), ReportGranularity::Hourly, ReportFinality::Estimated);
        $first = $run();
        $this->assertSame(ReportImportStatus::Completed, $first->status, $first->error_message ?? '');
        $this->assertSame(ReportGranularity::Daily, $first->granularity);
        $this->assertSame(ReportImportStatus::Duplicate, $legacy->fresh()->status);
        $this->assertArrayNotHasKey($legacyKey, $binding->connection->fresh()->configuration['google_jobs']);
        $this->assertSame('ACTIVE', $binding->connection->fresh()->status->value);
        $this->assertNull($binding->connection->fresh()->last_error);
        $this->assertNull($binding->connection->fresh()->last_finalized_import_at);
        $this->assertSame(ReportImportStatus::Completed, $run()->status);
        $this->assertDatabaseCount('hourly_reports', 0);
        $row = DailyReport::withoutGlobalScopes()->sole();
        $this->assertSame([130, 108, 22, 101, 4, 13500], array_map('intval', [$row->ad_requests, $row->matched_requests,
            $row->unfilled_requests, $row->impressions, $row->clicks, $row->gross_revenue_minor]));
        $this->assertFalse($row->settlement_eligible);
        $this->travelTo($day->addDay()->addHours(10));
        $final = $this->import($binding, '2026-09-21', '2026-09-21');
        $this->assertSame(ReportImportStatus::Completed, $final->status, $final->error_message ?? '');
        $row = $row->fresh();
        $this->assertSame('FINALIZED', $row->finality->value);
        $this->assertTrue($row->settlement_eligible);
        $this->assertSame(14000, (int) $row->gross_revenue_minor);
        $this->assertDatabaseCount('daily_reports', 1);
    }

    public function test_scheduler_recovers_a_previous_day_legacy_hourly_failure_as_finalized_daily_data(): void
    {
        $binding = $this->bind($this->context());
        $day = CarbonImmutable::parse('2026-09-20', 'Africa/Cairo');
        $legacy = ReportImportJob::withoutGlobalScopes()->create([
            'organization_id' => $binding->organization_id, 'report_source_connection_id' => $binding->connection->id,
            'import_type' => 'API', 'granularity' => 'HOURLY', 'finality' => 'ESTIMATED', 'status' => 'FAILED',
            'period_start' => $day, 'period_end' => $day->endOfDay(), 'idempotency_key' => hash('sha256', 'yesterday-hourly'),
            'error_message' => 'ReportError.COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS', 'next_retry_at' => now()->subMinute(),
        ]);
        $binding->connection->update(['configuration' => ['sync_due' => [
            'daily_2026-09-01_2026-09-20' => now()->addHours(6)->toIso8601String(),
            'intraday_2026-09-21' => now()->addHour()->toIso8601String(),
        ]]]);
        Http::fake(['storage.googleapis.com/*' => fn () => Http::response($this->csv())]);
        $results = app(SiteGamReportSynchronizer::class)->sync($binding->fresh());
        $this->assertCount(1, $results);
        $this->assertSame(ReportImportStatus::Completed, $results[0]->status, $results[0]->error_message ?? '');
        $this->assertSame(ReportGranularity::Daily, $results[0]->granularity);
        $this->assertSame(ReportFinality::Finalized, $results[0]->finality);
        $this->assertSame(ReportImportStatus::Duplicate, $legacy->fresh()->status);
        $this->assertDatabaseCount('hourly_reports', 0);
        $this->assertTrue(DailyReport::withoutGlobalScopes()->sole()->settlement_eligible);
        $this->assertSame([], app(SiteGamReportSynchronizer::class)->sync($binding->fresh()));
        $this->assertSame(1, $this->google->jobs);
    }

    public function test_expired_google_jobs_are_replaced_and_a_success_clears_prior_failures(): void
    {
        $binding = $this->bind($this->context());
        $this->google->status = 'IN_PROGRESS';
        $this->import($binding);
        $this->travel(7)->hours();
        $failed = $this->import($binding);
        $this->assertSame(ReportImportStatus::Failed, $failed->status);
        $this->assertSame(0, $binding->connection->imports()->where('status', 'PENDING')->count());
        $this->google->status = 'COMPLETED';
        Http::fake(['storage.googleapis.com/*' => fn () => Http::response($this->csv())]);
        $this->assertSame(ReportImportStatus::Completed, $this->import($binding)->status);
        $this->assertSame(2, $this->google->jobs);
        $this->assertSame(ReportImportStatus::Duplicate, $failed->fresh()->status);
        $this->assertSame('ACTIVE', $binding->connection->fresh()->status->value);
    }

    public function test_changing_unit_preserves_old_reports_and_versions_the_effective_dates(): void
    {
        $context = $this->context();
        $first = $this->bind($context);
        Http::fake(['storage.googleapis.com/*' => fn () => Http::response($this->csv())]);
        $this->import($first);
        $this->google->units = [['id' => '67890', 'name' => 'Replacement', 'adUnitCode' => 'replacement']];
        $second = $this->bind($context);
        $this->assertSame('2026-09-21', $second->starts_on->toDateString());
        $this->assertSame('2026-09-20', $first->fresh()->ends_on->toDateString());
        $this->assertNull($first->fresh()->active_site_id);
        $this->assertSame($context[3]->id, $second->active_site_id);
        $this->assertSame(12345, (int) DailyReport::withoutGlobalScopes()->sum('gross_revenue_minor'));
        $this->assertNotSame($first->report_source_connection_id, $second->report_source_connection_id);
        $this->assertSame(ReportImportStatus::Failed, $this->import($second)->status);
    }

    public function test_generic_reporting_endpoint_cannot_create_an_unverified_ad_unit_source(): void
    {
        [$admin] = $this->context();
        $source = ReportSource::query()->where('code', 'GAM_AD_UNIT')->sole();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.reporting.connections.store'), ['report_source_id' => $source->id, 'name' => 'Forged',
                'connection_type' => 'CUSTOM', 'currency' => 'USD', 'timezone' => 'UTC'])
            ->assertSessionHasErrors('report_source_id');
        $this->assertDatabaseCount('site_gam_report_bindings', 0);
        $this->assertDatabaseCount('report_source_connections', 0);
    }

    public function test_site_source_satisfies_direct_account_coverage_without_hiding_an_uncovered_second_site(): void
    {
        [$admin, $publisher, $user, $site] = $context = $this->context();
        $this->seed(DemandNetworkSeeder::class);
        $network = DemandNetwork::where('code', 'CUSTOM')->first() ?? DemandNetwork::firstOrFail();
        $account = DemandAccount::withoutGlobalScopes()->create([
            'organization_id' => $admin->organization_id, 'demand_network_id' => $network->id, 'name' => 'Site demand',
            'scope' => 'HORUS_MEDIA', 'integration_mode' => 'DIRECT_JS', 'approval_status' => 'APPROVED', 'is_enabled' => true,
        ]);
        $mapping = ['organization_id' => $site->organization_id, 'demand_account_id' => $account->id,
            'approval_status' => 'APPROVED', 'is_enabled' => true, 'integration_mode' => 'DIRECT_JS'];
        DemandSite::withoutGlobalScopes()->create($mapping + ['site_id' => $site->id]);
        $binding = $this->bind($context);
        $period = app(FinancialPeriodService::class)->periodFor('2026-09-01', 'USD');
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00'));
        Http::fake(['storage.googleapis.com/*' => fn () => Http::response($this->csv())]);
        $this->assertSame(ReportImportStatus::Completed, $this->import($binding, '2026-09-01', '2026-09-30')->status);
        $readiness = app(MonetizationFinancialReadinessService::class);
        $this->assertCount(1, $readiness->blockersForPeriod($period), 'Site GAM must not silently cover independent provider revenue.');

        $financialBinding = app(MonetizationFinancialBindingService::class)->bind(
            $account,
            ReportSource::query()->where('code', ReportSourceCode::CustomCsv->value)->firstOrFail(),
            FinancialReportingMethod::Csv,
            'USD',
            'UTC',
            $admin,
            [
                'site_gam_included' => true,
                'site_gam_inclusion_reason' => 'Provider contract confirms revenue is included in the bound Site GAM unit.',
            ],
        );
        $this->assertCount(0, $readiness->blockersForPeriod($period));

        try {
            app(ReportImportService::class)->importRows(
                $financialBinding->connection,
                [[
                    'date' => '2026-09-20',
                    'publisher_id' => $publisher->id,
                    'site_id' => $site->id,
                    'gross_revenue_minor' => 9999,
                    'currency' => 'USD',
                ]],
                ReportGranularity::Daily,
                ReportFinality::Finalized,
                CarbonImmutable::parse('2026-09-20'),
                CarbonImmutable::parse('2026-09-20'),
                $admin,
                'must-not-double-count-provider-revenue',
                importType: 'CSV',
            );
            $this->fail('Provider revenue must not be imported separately when Site GAM is declared canonical.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source', $exception->errors());
        }

        $other = $this->makeSiteFor($publisher, $user);
        $other->forceFill(['created_at' => '2026-09-21 00:00:00'])->save();
        DemandSite::withoutGlobalScopes()->create($mapping + ['site_id' => $other->id]);
        $this->assertCount(1, $readiness->blockersForPeriod($period));
    }

    public function test_declared_site_gam_coverage_is_exclusive_and_cannot_fallback_to_duplicate_provider_rows(): void
    {
        [$admin, $publisher, , $site] = $context = $this->context();
        $this->seed(DemandNetworkSeeder::class);
        $network = DemandNetwork::where('code', 'CUSTOM')->first() ?? DemandNetwork::firstOrFail();
        $account = DemandAccount::withoutGlobalScopes()->create([
            'organization_id' => $admin->organization_id,
            'demand_network_id' => $network->id,
            'name' => 'Attested Site GAM demand',
            'scope' => 'HORUS_MEDIA',
            'integration_mode' => 'DIRECT_JS',
            'approval_status' => 'APPROVED',
            'is_enabled' => true,
        ]);
        DemandSite::withoutGlobalScopes()->create([
            'organization_id' => $site->organization_id,
            'demand_account_id' => $account->id,
            'site_id' => $site->id,
            'approval_status' => 'APPROVED',
            'is_enabled' => true,
            'integration_mode' => 'DIRECT_JS',
        ]);

        $siteBinding = $this->bind($context);
        $period = app(FinancialPeriodService::class)->periodFor('2026-09-01', 'USD');
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00'));
        Http::fake(['storage.googleapis.com/*' => fn () => Http::response($this->csv())]);
        $siteJob = $this->import($siteBinding, '2026-09-01', '2026-09-30');
        $this->assertSame(ReportImportStatus::Completed, $siteJob->status, $siteJob->error_message ?? '');

        $financial = app(MonetizationFinancialBindingService::class)->bind(
            $account,
            ReportSource::query()->where('code', ReportSourceCode::CustomCsv->value)->firstOrFail(),
            FinancialReportingMethod::Csv,
            'USD',
            'UTC',
            $admin,
            [
                'site_gam_included' => true,
                'site_gam_inclusion_reason' => 'Provider settlement is contractually included in the bound Site GAM reporting unit.',
            ],
        );

        $providerDay = CarbonImmutable::parse('2026-09-20');
        try {
            app(ReportImportService::class)->importRows(
                $financial->connection,
                [[
                    'date' => $providerDay->toDateString(),
                    'publisher_id' => $publisher->id,
                    'site_id' => $site->id,
                    'impressions' => 95,
                    'gross_revenue_minor' => 99999,
                    'currency' => 'USD',
                ]],
                ReportGranularity::Daily,
                ReportFinality::Finalized,
                $providerDay,
                $providerDay,
                $admin,
                importType: 'CSV',
                sourceTotals: ['impressions' => 95, 'gross_revenue_minor' => 99999],
            );
            $this->fail('Provider-specific revenue must not import while Site GAM is the declared canonical source.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source', $exception->errors());
            $this->assertStringContainsString('Site GAM', $exception->errors()['source'][0]);
        }

        $this->assertSame(12345, (int) DailyReport::withoutGlobalScopes()->sum('gross_revenue_minor'));
        $this->assertCount(0, app(MonetizationFinancialReadinessService::class)->blockersForPeriod($period));

        DailyReport::withoutGlobalScopes()
            ->where('report_source_connection_id', $siteBinding->report_source_connection_id)
            ->whereDate('report_date', '2026-09-25')
            ->delete();

        $blockers = app(MonetizationFinancialReadinessService::class)->blockersForPeriod($period);
        $providerBlocker = $blockers->firstWhere('subject_id', $account->id);
        $this->assertNotNull($providerBlocker);
        $this->assertSame('SITE_GAM_DECLARED_COVERAGE_INCOMPLETE', $providerBlocker['reasons'][0]['code']);
    }

    public function test_full_network_import_cannot_duplicate_the_bound_google_unit_even_without_a_site_mapping(): void
    {
        [$admin, , , , $gam] = $context = $this->context();
        $this->bind($context);
        $network = app(ReportingBridge::class)->connectionForGam($gam, $admin);
        $day = CarbonImmutable::parse('2026-09-20');
        $job = app(ReportImportService::class)->importRows($network, [
            ['date' => '2026-09-20', 'ad_unit_id' => '12345', 'gross_revenue_minor' => 1000],
            ['date' => '2026-09-20', 'ad_unit_id' => '67890', 'gross_revenue_minor' => 2000],
        ], ReportGranularity::Daily, ReportFinality::Finalized, $day, $day, $admin, importType: 'API');
        $this->assertSame(ReportImportStatus::Completed, $job->status);
        $this->assertSame(1, $job->row_count);
        $this->assertSame(2000, (int) DailyReport::withoutGlobalScopes()->sum('gross_revenue_minor'));
    }

    public function test_site_gam_ownership_does_not_suppress_independent_provider_financial_rows(): void
    {
        [$admin, , , $site] = $context = $this->context();
        $this->bind($context);
        $this->seed(DemandNetworkSeeder::class);

        $network = DemandNetwork::where('code', 'CUSTOM')->first() ?? DemandNetwork::firstOrFail();
        $account = DemandAccount::withoutGlobalScopes()->create([
            'organization_id' => $admin->organization_id,
            'demand_network_id' => $network->id,
            'name' => 'Independent Direct Provider',
            'scope' => 'HORUS_MEDIA',
            'integration_mode' => 'DIRECT_JS',
            'approval_status' => 'APPROVED',
            'is_enabled' => true,
        ]);
        DemandSite::withoutGlobalScopes()->create([
            'organization_id' => $site->organization_id,
            'demand_account_id' => $account->id,
            'site_id' => $site->id,
            'approval_status' => 'APPROVED',
            'is_enabled' => true,
            'integration_mode' => 'DIRECT_JS',
        ]);
        $financial = app(MonetizationFinancialBindingService::class)->bind(
            $account,
            ReportSource::query()->where('code', ReportSourceCode::CustomCsv->value)->firstOrFail(),
            FinancialReportingMethod::Csv,
            'USD',
            'UTC',
            $admin,
        );

        $day = CarbonImmutable::parse('2026-09-20');
        $job = app(ReportImportService::class)->importRows(
            $financial->connection,
            [[
                'date' => $day->toDateString(),
                'publisher_id' => $site->publisher_id,
                'site_id' => $site->id,
                'impressions' => 25,
                'gross_revenue_minor' => 2500,
                'currency' => 'USD',
            ]],
            ReportGranularity::Daily,
            ReportFinality::Finalized,
            $day,
            $day,
            $admin,
            'independent-provider-row',
            importType: 'CSV',
        );

        $this->assertSame(ReportImportStatus::Completed, $job->status, $job->error_message ?? '');
        $this->assertSame(1, $job->row_count);
        $this->assertDatabaseHas('daily_reports', [
            'report_source_connection_id' => $financial->report_source_connection_id,
            'gross_revenue_minor' => 2500,
        ]);
    }

    public function test_site_health_tracks_the_reporting_unit_independently_of_the_serving_engines(): void
    {
        $context = $this->context();
        $binding = $this->bind($context);
        $health = app(ReportingHealthService::class);
        $this->assertSame('PENDING', $health->forSite($context[3])['status']);
        Http::fake(['storage.googleapis.com/*' => fn () => Http::response($this->csv())]);
        $this->import($binding);
        $result = $health->forSite($context[3]);
        $this->assertSame('ACTIVE', $result['status']);
        $this->assertSame('GAM_AD_UNIT', $result['sources'][0]['report_source']);
        $this->assertSame('REPORTING', $result['sources'][0]['engine']);
        $this->assertNull($context[3]->fresh()->gam_connection_id);
        $binding->connection->update(['status' => 'ERROR']);
        $this->assertSame('DEGRADED', $health->forSite($context[3])['status']);
    }

    public function test_network_currency_is_metadata_while_usd_drives_site_gam_financial_coverage(): void
    {
        [$admin, , , $site] = $context = $this->context();
        $this->seed(DemandNetworkSeeder::class);
        $account = DemandAccount::withoutGlobalScopes()->create([
            'organization_id' => $admin->organization_id, 'demand_network_id' => DemandNetwork::firstOrFail()->id,
            'name' => 'Demand account with canonical USD reporting', 'scope' => 'HORUS_MEDIA', 'integration_mode' => 'DIRECT_JS',
            'approval_status' => 'APPROVED', 'is_enabled' => true,
        ]);
        DemandSite::withoutGlobalScopes()->create(['organization_id' => $site->organization_id,
            'demand_account_id' => $account->id, 'site_id' => $site->id, 'is_enabled' => true,
            'approval_status' => 'APPROVED', 'integration_mode' => 'DIRECT_JS']);
        $this->google->currency = 'EGP';
        $binding = $this->bind($context);
        $this->assertSame('USD', $binding->connection->currency);
        $this->assertSame('EGP', data_get($binding->connection->configuration, 'source_network_currency'));

        app(MonetizationFinancialBindingService::class)->bind(
            $account,
            ReportSource::query()->where('code', ReportSourceCode::CustomCsv->value)->firstOrFail(),
            FinancialReportingMethod::Csv,
            'USD',
            'Africa/Cairo',
            $admin,
            [
                'site_gam_included' => true,
                'site_gam_inclusion_reason' => 'Provider settlement is explicitly included in the canonical USD Site GAM reporting unit.',
            ],
        );

        $usd = app(FinancialPeriodService::class)->periodFor('2026-09-01', 'USD');
        $egp = app(FinancialPeriodService::class)->periodFor('2026-09-01', 'EGP');
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00'));
        Http::fake(['storage.googleapis.com/*' => fn () => Http::response($this->csv())]);
        $this->assertSame(ReportImportStatus::Completed, $this->import($binding, '2026-09-01', '2026-09-30')->status);

        $query = collect($this->google->calls)->firstWhere('method', 'runReportJob')['payload']['reportJob']['reportQuery'];
        $this->assertSame('USD', $query['reportCurrency']);
        $this->assertSame(['USD'], DailyReport::withoutGlobalScopes()->pluck('currency')->unique()->values()->all());

        $readiness = app(MonetizationFinancialReadinessService::class);
        $this->assertCount(0, $readiness->blockersForPeriod($usd));
        $this->assertCount(0, $readiness->blockersForPeriod($egp), 'The Google network currency must not create an EGP Site GAM liability.');

        DailyReport::withoutGlobalScopes()
            ->where('report_source_connection_id', $binding->report_source_connection_id)
            ->whereDate('report_date', '2026-09-25')
            ->delete();
        $this->assertCount(1, $readiness->blockersForPeriod($usd));
        $this->assertCount(0, $readiness->blockersForPeriod($egp));
    }
}
