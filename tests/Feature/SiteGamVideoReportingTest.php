<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\PublisherInvoiceStatus;
use App\Enums\PublisherPaymentProfileStatus;
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
use App\Models\PublisherContract;
use App\Models\PublisherStatement;
use App\Models\ReportImportJob;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Models\SiteGamReportBinding;
use App\Models\SiteGamVideoReportBinding;
use App\Services\Reporting\SiteGamVideoReportingService;
use Illuminate\Support\Facades\DB;
use App\Services\Gam\Contracts\GamSoapTransportInterface;
use App\Services\Gam\GamSoapPayloadHydrator;
use App\Services\Gam\GamSoapVersionResolver;
use App\Services\Monetization\ReportingHealthService;
use App\Services\Reporting\Connectors\GamAdUnitReportConnector;
use App\Services\Reporting\FinancialPeriodService;
use App\Services\Reporting\MonetizationFinancialBindingService;
use App\Services\Reporting\MonetizationFinancialReadinessService;
use App\Services\Reporting\ReportImportService;
use App\Services\Reporting\PublisherStatementService;
use App\Services\Reporting\PublisherPaymentService;
use App\Services\Reporting\PublisherPaymentProfileService;
use App\Services\Reporting\ReportingBridge;
use App\Services\Reporting\RevenueRuleService;
use App\Services\Reporting\SiteGamFinancialCoverage;
use App\Services\Reporting\SiteGamReportingService;
use App\Services\Reporting\SiteGamReportSynchronizer;
use App\Services\Reporting\SiteGamReportMetrics;
use App\Services\Reporting\SiteGamTodayReport;
use App\Services\Reporting\UnifiedReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Database\Seeders\ReportingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class SiteGamVideoReportingTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private object $google;
    private string $reportHostname;

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
        $this->reportHostname = $site->primary_domain;
        $gam = $this->makeGamConnection($horus, $admin);
        $this->google = new class implements GamSoapTransportInterface
        {
            public array $calls = [];

            public array $units = [['id' => '12345', 'name' => 'Publisher unit', 'adUnitCode' => 'publisher_unit']];

            public string $status = 'COMPLETED';
            public bool $failStatus = false;

            public string $url = 'https://storage.googleapis.com/report.csv?signature=private-download';

            public string $timezone = 'Africa/Cairo';

            public string $currency = 'USD';

            public int $jobs = 0;

            public ?string $reportCurrency = null;
            public bool $rejectSiteDimension = false;
            public bool $rejectOptionalViewability = false;

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
                if ($method === 'runReportJob' && $this->rejectSiteDimension
                    && in_array('SITE_NAME', $payload['reportJob']['reportQuery']['dimensions'], true)) {
                    throw new \RuntimeException('ReportError.COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS');
                }

                if ($method === 'runReportJob' && $this->rejectOptionalViewability
                    && array_intersect(array_keys(SiteGamReportMetrics::OPTIONAL_COLUMNS), $payload['reportJob']['reportQuery']['columns'])) {
                    throw new \RuntimeException('ReportError.COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS');
                }

                if ($method === 'getReportJobStatus' && $this->failStatus) throw new \RuntimeException('Transient transport unavailable');

                return match ($method) {
                    'getCurrentNetwork' => ['networkCode' => $connection->network_code, 'currencyCode' => $this->currency, 'timeZone' => $this->timezone],
                    'getAdUnitsByStatement' => ['results' => $this->units],
                    'runReportJob' => ['id' => (string) ++$this->jobs, 'reportQuery' => ['reportCurrency' => $this->reportCurrency]],
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

    private function csv(array $rows = [['2026-09-21', '67890', 120, 100, 95, 3, 'US$ 1000000']], bool $hourly = false, ?string $hostname = null, bool $viewability = true): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, ['Dimension.DATE', ...($hourly ? ['Dimension.HOUR'] : []), 'Dimension.AD_UNIT_ID', 'Dimension.SITE_NAME',
            ...array_map(fn ($column) => 'Column.'.$column, array_keys($viewability ? SiteGamReportMetrics::COLUMNS : SiteGamReportMetrics::CORE_COLUMNS))], escape: '');
        foreach ($rows as $row) {
            if ($viewability && count($row) === ($hourly ? 8 : 7)) {
                $row = [...$row, 40, 80];
            }
            array_splice($row, $hourly ? 3 : 2, 0, [$hostname ?? $this->reportHostname]);
            fputcsv($stream, $row, escape: '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    private function import(SiteGamReportBinding $binding, string $from = '2026-09-21', string $to = '2026-09-21')
    {
        return app(ReportImportService::class)->runConnection($binding->connection, CarbonImmutable::parse($from),
            CarbonImmutable::parse($to), ReportGranularity::Daily, ReportFinality::Finalized);
    }

    private function video(array $context, string $unit = '67890'): SiteGamVideoReportBinding
    {
        $this->google->units = [['id' => $unit, 'name' => 'Video unit', 'adUnitCode' => 'video_unit']];
        try {
            return app(SiteGamVideoReportingService::class)->bind($context[3], $context[4]->id, $unit, $context[0]);
        } finally {
            $this->google->units = [['id' => '12345', 'name' => 'Publisher unit', 'adUnitCode' => 'publisher_unit']];
        }
    }

    private function finalizeClock(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC'));
    }

    private function assertCompleted($job): void
    {
        $this->assertSame(ReportImportStatus::Completed, $job->status, $job->error_message ?? '');
    }

    public function test_video_binding_is_optional_independent_forward_only_and_idempotent_without_serving_changes(): void
    {
        $context = $this->context();
        $primary = $this->bind($context);
        $siteBefore = $context[3]->fresh()->getAttributes();
        $configsBefore = ConfigVersion::query()->count();
        $primaryBefore = $primary->fresh()->getAttributes();
        $connectionBefore = $primary->connection->fresh()->getAttributes();
        $video = $this->video($context);
        $this->assertSame('2026-09-21', $video->starts_on->toDateString());
        $this->assertSame('SITE_GAM_VIDEO_AD_UNIT', $video->connection->connection_type);
        $this->assertSame('GAM_VIDEO_AD_UNIT', $video->connection->source->code->value);
        $this->assertNotSame($primary->report_source_connection_id, $video->report_source_connection_id);
        $this->assertSame($video->id, $this->video($context)->id);
        $this->assertSame($siteBefore, $context[3]->fresh()->getAttributes());
        $this->assertSame($primaryBefore, $primary->fresh()->getAttributes());
        $this->assertSame($connectionBefore, $primary->connection->fresh()->getAttributes());
        $this->assertDatabaseCount('site_gam_report_bindings', 1);
        $this->assertDatabaseCount('site_gam_video_report_bindings', 1);
        $this->assertDatabaseCount('daily_reports', 0);
        $this->assertSame($configsBefore, ConfigVersion::query()->count());
        $this->assertDatabaseCount('site_gam_reporting_unit_claims', 2);
    }

    public function test_cross_purpose_and_duplicate_credential_unit_claims_cannot_credit_the_same_unit_twice(): void
    {
        $context = $this->context();
        $primary = $this->bind($context);
        try {
            $this->video($context, '12345');
            $this->fail('Primary and Video must not own the same physical unit.');
        } catch (ValidationException) { $this->addToAssertionCount(1); }
        $video = $this->video($context);
        $other = $context;
        $other[3] = $this->makeSiteFor($context[1], $context[2]);
        $other[4] = $this->makeGamConnection($context[0]->organization, $context[0], ['network_code' => $context[4]->network_code]);
        try {
            $this->video($other);
            $this->fail('Duplicate credentials must not evade a physical unit claim.');
        } catch (ValidationException) { $this->addToAssertionCount(1); }
        $this->google->units = [['id' => '67890', 'name' => 'Video unit', 'adUnitCode' => 'video_unit']];
        try {
            app(SiteGamReportingService::class)->bind($other[3], $other[4]->id, '67890', $other[0]);
            $this->fail('A primary binding must respect the existing video unit claim.');
        } catch (ValidationException) { $this->addToAssertionCount(1); }
        $this->assertSame('12345', $primary->fresh()->ad_unit_id);
        $this->assertSame('67890', $video->fresh()->ad_unit_id);
        $this->assertDatabaseCount('site_gam_reporting_unit_claims', 2);
    }

    public function test_exact_hostname_adx_usd_and_date_effective_website_share_use_the_normal_financial_pipeline(): void
    {
        [$admin, , , $site] = $context = $this->context();
        $this->google->currency = 'AED';
        $primary = $this->bind($context);
        $video = $this->video($context);
        foreach ([['2026-09-01', 8000], ['2026-09-22', 7000]] as [$date, $share]) {
            app(RevenueRuleService::class)->createRule(['name' => 'Website dated split '.$date, 'scope_type' => 'WEBSITE', 'scope_id' => $site->id,
                'effective_from' => $date, 'effective_to' => $date === '2026-09-01' ? '2026-09-21' : null,
                'publisher_share_bp' => $share, 'horus_share_bp' => 10000 - $share, 'mcm_partner_share_bp' => 0], $admin);
        }
        $this->finalizeClock();
        $rows = [['2026-09-21', '67890', 120, 100, 95, 3, 'US$ 1000000'], ['2026-09-22', '67890', 120, 100, 95, 3, 'US$ 1000000']];
        $csv = $this->csv($rows);
        foreach (['other.example', '(unknown)', 'www.'.$this->reportHostname] as $hostname) {
            $foreign = $this->csv($rows, hostname: $hostname);
            $csv .= substr($foreign, strpos($foreign, "\n") + 1);
        }
        Http::fake(['storage.googleapis.com/*' => fn () => Http::response($csv)]);
        $this->assertCompleted($this->import($video, '2026-09-21', '2026-09-22'));
        $facts = DailyReport::withoutGlobalScopes()->orderBy('report_date')->get();
        $this->assertSame([100, 100], $facts->pluck('gross_revenue_minor')->map(fn ($value) => (int) $value)->all());
        $this->assertSame([80, 70], $facts->pluck('publisher_earnings_minor')->map(fn ($value) => (int) $value)->all());
        $this->assertCount(2, $facts->pluck('revenue_rule_version_id')->unique());
        $this->assertSame([20, 30], $facts->pluck('horus_earnings_minor')->map(fn ($value) => (int) $value)->all());
        $this->assertSame(['USD'], $facts->pluck('currency')->unique()->values()->all());
        foreach ($facts as $fact) {
            $this->assertTrue($fact->settlement_eligible);
            $this->assertSame($site->id, $fact->dimension->site_id);
            $this->assertSame('67890', data_get($fact->dimension->external_dimensions, 'gam_ad_unit_id'));
            $this->assertSame('AD_EXCHANGE_V1', data_get($fact->dimension->external_dimensions, 'gam_report_basis'));
            $this->assertSame($this->reportHostname, data_get($fact->dimension->external_dimensions, 'gam_report_site'));
            $this->assertNull($fact->unfilled_impressions);
        }
        $query = collect($this->google->calls)->firstWhere('method', 'runReportJob')['payload']['reportJob']['reportQuery'];
        $this->assertSame(['DATE', 'AD_UNIT_ID', 'SITE_NAME'], $query['dimensions']);
        $this->assertSame('USD', $query['reportCurrency']);
        $this->assertSame('67890', $query['statement']['values'][0]['value']['value']);
        $this->assertContains('AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE', $query['columns']);
        $this->assertSame('12345', $primary->fresh()->ad_unit_id);
        $this->assertStringNotContainsString('private-download', GamApiOperation::query()->get()->toJson());
    }

    public function test_pending_repeated_new_and_failed_jobs_cannot_duplicate_or_erase_video_money(): void
    {
        $video = $this->video($this->context());
        $this->finalizeClock();
        $this->google->status = 'IN_PROGRESS';
        $pending = $this->import($video);
        $this->assertSame(ReportImportStatus::Pending, $pending->status);
        $this->import($video);
        $this->assertSame(1, $this->google->jobs);
        $this->assertDatabaseCount('daily_reports', 0);
        $this->google->status = 'COMPLETED';
        $csv = $this->csv();
        Http::fake(['storage.googleapis.com/*' => function () use (&$csv) { return Http::response($csv); }]);
        $this->assertCompleted($this->import($video));
        $this->assertSame(ReportImportStatus::Duplicate, $pending->fresh()->status);
        $this->assertCompleted($this->import($video));
        $this->assertSame(2, $this->google->jobs);
        $this->assertDatabaseCount('daily_reports', 1);
        $fact = DailyReport::withoutGlobalScopes()->sole();
        $before = $fact->getAttributes();
        $csv = '<html>bad report</html>';
        $this->assertSame(ReportImportStatus::Failed, $this->import($video)->status);
        $this->assertSame($before, $fact->fresh()->getAttributes());
        $csv = $this->csv();
        $this->assertCompleted($this->import($video));
        $this->assertDatabaseCount('daily_reports', 1);
        $this->assertSame(100, (int) DailyReport::withoutGlobalScopes()->sum('gross_revenue_minor'));
    }

    public function test_estimate_becomes_one_finalized_fact_and_cannot_be_downgraded(): void
    {
        $video = $this->video($this->context());
        $csv = $this->csv();
        Http::fake(['storage.googleapis.com/*' => function () use (&$csv) { return Http::response($csv); }]);
        $day = CarbonImmutable::parse('2026-09-21');
        $this->assertCompleted(app(ReportImportService::class)->runConnection($video->connection, $day, $day, ReportGranularity::Daily, ReportFinality::Estimated));
        $fact = DailyReport::withoutGlobalScopes()->sole();
        $this->assertFalse($fact->settlement_eligible);
        $this->assertSame(ReportFinality::Estimated, $fact->finality);
        $this->finalizeClock();
        $csv = $this->csv([['2026-09-21', '67890', 140, 130, 125, 4, 'US$ 2000000']]);
        $this->assertCompleted($this->import($video));
        $this->assertDatabaseCount('daily_reports', 1);
        $this->assertSame($fact->id, DailyReport::withoutGlobalScopes()->sole()->id);
        $this->assertTrue($fact->fresh()->settlement_eligible);
        $this->assertSame(ReportFinality::Finalized, $fact->fresh()->finality);
        $csv = $this->csv([['2026-09-21', '67890', 90, 80, 75, 1, 'US$ 3000000']]);
        $downgrade = app(ReportImportService::class)->runConnection($video->connection, $day, $day, ReportGranularity::Daily, ReportFinality::Estimated);
        $this->assertSame(ReportImportStatus::Failed, $downgrade->status);
        $this->assertSame(200, (int) $fact->fresh()->gross_revenue_minor);
        $this->assertDatabaseCount('hourly_reports', 0);
    }

    public function test_video_missing_day_blocks_close_and_combined_statement_counts_both_sources_once(): void
    {
        [$admin] = $context = $this->context();
        $primary = $this->bind($context);
        $video = $this->video($context);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
        $periods = app(FinancialPeriodService::class);
        $period = $periods->periodFor('2026-09-01', 'USD');
        $csv = $this->csv([['2026-09-21', '12345', 120, 100, 95, 3, 'US$ 1000000']]);
        Http::fake(['storage.googleapis.com/*' => function () use (&$csv) { return Http::response($csv); }]);
        $this->assertCompleted($this->import($primary, '2026-09-01', '2026-09-30'));
        // Main canonical coverage for an attested provider must not suppress
        // the independent Video purpose's missing-day blocker.
        $this->seed(DemandNetworkSeeder::class);
        $account = DemandAccount::withoutGlobalScopes()->create([
            'organization_id' => $admin->organization_id, 'demand_network_id' => DemandNetwork::firstOrFail()->id,
            'name' => 'Attested primary coverage', 'scope' => 'HORUS_MEDIA', 'integration_mode' => 'DIRECT_JS',
            'approval_status' => 'APPROVED', 'is_enabled' => true,
        ]);
        $account->forceFill(['created_at' => '2026-09-21 00:00:00'])->save();
        $mapping = DemandSite::withoutGlobalScopes()->create(['organization_id' => $context[3]->organization_id,
            'demand_account_id' => $account->id, 'site_id' => $context[3]->id, 'approval_status' => 'APPROVED',
            'is_enabled' => true, 'integration_mode' => 'DIRECT_JS']);
        $mapping->forceFill(['created_at' => '2026-09-21 00:00:00'])->save();
        app(MonetizationFinancialBindingService::class)->bind($account,
            ReportSource::where('code', ReportSourceCode::CustomCsv->value)->firstOrFail(), FinancialReportingMethod::Csv,
            'USD', 'UTC', $admin, ['site_gam_included' => true,
                'site_gam_inclusion_reason' => 'Provider contract includes revenue in the primary Site GAM unit.']);
        $this->assertCount(1, app(MonetizationFinancialReadinessService::class)->blockersForPeriod($period)->where('subject_type', 'SITE_GAM_VIDEO_AD_UNIT'));
        $blockers = app(SiteGamFinancialCoverage::class)->blockers($period);
        $this->assertCount(1, $blockers);
        $this->assertSame('SITE_GAM_VIDEO_AD_UNIT', $blockers->sole()['subject_type']);
        $this->assertFalse($periods->readiness($period)['ready']);
        $csv = $this->csv([['2026-09-21', '67890', 120, 100, 95, 3, 'US$ 2000000']]);
        $this->assertCompleted($this->import($video, '2026-09-21', '2026-09-29'));
        $this->assertCount(1, app(SiteGamFinancialCoverage::class)->blockers($period));
        $this->assertCompleted($this->import($video, '2026-09-21', '2026-09-30'));
        $this->assertCount(0, app(SiteGamFinancialCoverage::class)->blockers($period));
        $readiness = $periods->readiness($period);
        $this->assertTrue($readiness['ready'], json_encode($readiness));
        $this->assertSame(300, (int) DailyReport::withoutGlobalScopes()->sum('gross_revenue_minor'));
        $periods->close($period, $admin);
        $statement = PublisherStatement::withoutGlobalScopes()->sole();
        $this->assertSame(300, (int) $statement->gross_revenue_minor);
        $this->assertSame(210, (int) $statement->publisher_earnings_minor);
        $snapshot = $statement->snapshot_hash;
        $periods->close($period->fresh(), $admin);
        $this->assertDatabaseCount('publisher_statements', 1);
        $this->assertSame($snapshot, $statement->fresh()->snapshot_hash);
        $this->assertSame(ReportImportStatus::BlockedClosedPeriod, $this->import($video)->status);
    }

    public function test_full_network_owned_units_are_excluded_and_distinct_units_remain_importable(): void
    {
        [$admin, , , , $gam] = $context = $this->context();
        $this->bind($context);
        $this->video($context);
        $this->finalizeClock();
        $network = app(ReportingBridge::class)->connectionForGam($gam, $admin);
        $day = CarbonImmutable::parse('2026-09-21');
        $job = app(ReportImportService::class)->importRows($network, [
            ['date' => '2026-09-21', 'ad_unit_id' => '12345', 'gross_revenue_minor' => 1000],
            ['date' => '2026-09-21', 'ad_unit_id' => '67890', 'gross_revenue_minor' => 2000],
            ['date' => '2026-09-21', 'ad_unit_id' => '99999', 'gross_revenue_minor' => 3000],
        ], ReportGranularity::Daily, ReportFinality::Finalized, $day, $day, $admin, importType: 'API');
        $this->assertCompleted($job);
        $this->assertSame(1, $job->row_count);
        $this->assertSame(3000, (int) DailyReport::withoutGlobalScopes()->sum('gross_revenue_minor'));
    }

    public function test_video_activation_after_prior_money_preserves_history_and_starts_after_the_last_day(): void
    {
        $context = $this->context();
        $primary = $this->bind($context);
        Http::fake(['storage.googleapis.com/*' => fn () => Http::response($this->csv([['2026-09-21', '12345', 120, 100, 95, 3, 'US$ 1000000']]))]);
        $day = CarbonImmutable::parse('2026-09-21');
        $this->assertCompleted(app(ReportImportService::class)->runConnection($primary->connection, $day, $day, ReportGranularity::Daily, ReportFinality::Estimated));
        $before = DB::table('daily_reports')->get()->toJson();
        $video = $this->video($context);
        $this->assertSame('2026-09-22', $video->starts_on->toDateString());
        $this->assertSame($before, DB::table('daily_reports')->get()->toJson());
        $this->assertSame('12345', $primary->fresh()->ad_unit_id);
        $this->assertSame(ReportImportStatus::Duplicate, $this->import($video, '2026-09-20', '2026-09-20')->status);
        $this->assertSame($before, DB::table('daily_reports')->get()->toJson());
    }
    public function test_cairo_same_day_disable_keeps_owned_estimates_finalizable_and_primary_unchanged(): void
    {
        $context = $this->context();
        $primary = $this->bind($context);
        $video = $this->video($context);
        $primaryBefore = $primary->fresh()->getAttributes();
        $connectionBefore = $primary->connection->fresh()->getAttributes();
        Http::fake(['storage.googleapis.com/*' => fn () => Http::response($this->csv())]);
        $day = CarbonImmutable::parse('2026-09-21');
        $this->assertCompleted(app(ReportImportService::class)->runConnection($video->connection, $day, $day, ReportGranularity::Daily, ReportFinality::Estimated));
        app(SiteGamVideoReportingService::class)->disable($context[3], $context[0]);
        $video->refresh();
        $this->assertNull($video->cancelled_at);
        $this->assertSame('2026-09-21', $video->ends_on->toDateString());
        $this->assertTrue($video->connection->fresh()->is_enabled);
        $this->assertNull($video->active_site_id);
        $this->finalizeClock();
        $this->assertCompleted($this->import($video));
        $this->assertTrue(DailyReport::withoutGlobalScopes()->sole()->settlement_eligible);
        $this->assertSame($primaryBefore, $primary->fresh()->getAttributes());
        $this->assertSame($connectionBefore, $primary->connection->fresh()->getAttributes());
    }

    public function test_disabling_a_future_binding_cancels_without_future_financial_liability(): void
    {
        $context = $this->context();
        $primary = $this->bind($context);
        Http::fake(['storage.googleapis.com/*' => fn () => Http::response($this->csv([['2026-09-21', '12345', 120, 100, 95, 3, 'US$ 1000000']]))]);
        $day = CarbonImmutable::parse('2026-09-21');
        $this->assertCompleted(app(ReportImportService::class)->runConnection($primary->connection, $day, $day, ReportGranularity::Daily, ReportFinality::Estimated));
        $video = $this->video($context);
        $this->assertSame('2026-09-22', $video->starts_on->toDateString());
        $before = DB::table('daily_reports')->get()->toJson();
        app(SiteGamVideoReportingService::class)->disable($context[3], $context[0]);
        $video->refresh();
        $this->assertNotNull($video->cancelled_at);
        $this->assertSame('2026-09-21', $video->ends_on->toDateString());
        $this->assertFalse($video->connection->fresh()->is_enabled);
        $period = app(FinancialPeriodService::class)->periodFor('2026-09-01', 'USD');
        $this->assertCount(0, app(SiteGamFinancialCoverage::class)->blockers($period)->where('subject_type', 'SITE_GAM_VIDEO_AD_UNIT'));
        $this->assertSame($before, DB::table('daily_reports')->get()->toJson());
        $this->assertDatabaseCount('site_gam_reporting_unit_claims', 1);
    }

    public function test_rebinding_retires_straddling_pending_job_and_only_owned_dates_finalize(): void
    {
        $context = $this->context();
        $old = $this->video($context);
        $this->travelTo(CarbonImmutable::parse('2026-09-22 12:00:00', 'UTC'));
        $this->google->status = 'IN_PROGRESS';
        $pending = app(ReportImportService::class)->runConnection($old->connection, CarbonImmutable::parse('2026-09-21'),
            CarbonImmutable::parse('2026-09-22'), ReportGranularity::Daily, ReportFinality::Estimated);
        $this->assertSame(ReportImportStatus::Pending, $pending->status);
        $new = $this->video($context, '77777');
        $this->assertSame('2026-09-21', $old->fresh()->ends_on->toDateString());
        $this->assertSame('2026-09-22', $new->starts_on->toDateString());
        $this->assertSame(ReportImportStatus::Duplicate, $pending->fresh()->status);
        $this->assertEmpty(data_get($old->connection->fresh()->configuration, 'google_jobs', []));
        $new->update(['ends_on' => '2026-09-22']);
        $this->google->status = 'COMPLETED';
        $this->finalizeClock();
        Http::fake(['storage.googleapis.com/*' => function () {
            $query = collect($this->google->calls)->where('method', 'runReportJob')->last()['payload']['reportJob']['reportQuery'];
            $unit = $query['statement']['values'][0]['value']['value'];
            return Http::response($this->csv([[$unit === '67890' ? '2026-09-21' : '2026-09-22', $unit, 120, 100, 95, 3, 'US$ 1000000']]));
        }]);
        foreach ([$old->fresh(), $new->fresh()] as $binding) {
            $jobs = app(SiteGamReportSynchronizer::class)->sync($binding);
            $this->assertNotEmpty($jobs);
            foreach ($jobs as $job) $this->assertCompleted($job);
        }
        $this->assertDatabaseCount('daily_reports', 2);
        $this->assertSame(['2026-09-21'], DailyReport::withoutGlobalScopes()->where('report_source_connection_id', $old->report_source_connection_id)->get()->map(fn ($row) => $row->report_date->toDateString())->all());
        $this->assertSame(0, ReportImportJob::withoutGlobalScopes()->whereIn('status', ['FAILED', 'PENDING'])->count());
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
        $periods = app(FinancialPeriodService::class);
        $period = $periods->periodFor('2026-09-01', 'USD');
        $readiness = $periods->readiness($period);
        $this->assertTrue($readiness['ready'], json_encode($readiness));
        $periods->close($period, $context[0]);
        $this->assertSame(200, (int) PublisherStatement::withoutGlobalScopes()->sole()->gross_revenue_minor);
    }

    public function test_video_only_does_not_cover_primary_provider_attestation_and_rejects_unknown_site_rows(): void
    {
        [$admin, , , $site, $gam] = $context = $this->context();
        $video = $this->video($context);
        $video->update(['ends_on' => '2026-09-21']);
        $this->finalizeClock();
        Http::fake(['storage.googleapis.com/*' => fn () => Http::response($this->csv())]);
        $this->assertCompleted($this->import($video));
        $period = app(FinancialPeriodService::class)->periodFor('2026-09-01', 'USD');
        $this->assertFalse(app(SiteGamFinancialCoverage::class)->coversSite($site->id, $period));
        $network = app(ReportingBridge::class)->connectionForGam($gam, $admin);
        $day = CarbonImmutable::parse('2026-09-21');
        $before = DB::table('daily_reports')->get()->toJson();
        $job = app(ReportImportService::class)->importRows($network, [['date' => '2026-09-21',
            'site_id' => $site->id, 'publisher_id' => $context[1]->id, 'gross_revenue_minor' => 9999]],
            ReportGranularity::Daily, ReportFinality::Finalized, $day, $day, $admin, importType: 'API');
        $this->assertSame(ReportImportStatus::Failed, $job->status);
        $this->assertSame($before, DB::table('daily_reports')->get()->toJson());
    }
    public function test_duplicate_credential_network_history_moves_video_forward_without_same_day_credit(): void
    {
        [$admin, , , , $gam] = $context = $this->context();
        $duplicate = $this->makeGamConnection($admin->organization, $admin, ['network_code' => $gam->network_code]);
        $network = app(ReportingBridge::class)->connectionForGam($duplicate, $admin);
        $day = CarbonImmutable::parse('2026-09-21');
        $this->assertCompleted(app(ReportImportService::class)->importRows($network, [[
            'date' => '2026-09-21', 'ad_unit_id' => '67890', 'gam_connection_id' => $duplicate->id,
            'gross_revenue_minor' => 1000, 'currency' => 'USD',
        ]], ReportGranularity::Daily, ReportFinality::Estimated, $day, $day, $admin, importType: 'API'));
        $legacy = DailyReport::withoutGlobalScopes()->sole();
        $this->assertNull($legacy->dimension->site_id);
        $before = $legacy->getAttributes();
        $video = $this->video($context);
        $this->assertSame('2026-09-22', $video->starts_on->toDateString());
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $this->finalizeClock();
        $this->assertSame(ReportImportStatus::Duplicate, $this->import($video)->status);
        $this->assertDatabaseCount('daily_reports', 1);
        $this->assertSame(1000, (int) DailyReport::withoutGlobalScopes()->sum('gross_revenue_minor'));
    }

    public function test_existing_unassigned_same_network_unit_fact_blocks_overlapping_video_import(): void
    {
        [$admin, , , , $gam] = $context = $this->context();
        $duplicate = $this->makeGamConnection($admin->organization, $admin, ['network_code' => $gam->network_code]);
        $network = app(ReportingBridge::class)->connectionForGam($duplicate, $admin);
        $day = CarbonImmutable::parse('2026-09-20');
        $this->assertCompleted(app(ReportImportService::class)->importRows($network, [[
            'date' => '2026-09-20', 'ad_unit_id' => '67890', 'gam_connection_id' => $duplicate->id,
            'gross_revenue_minor' => 1000, 'currency' => 'USD',
        ]], ReportGranularity::Daily, ReportFinality::Finalized, $day, $day, $admin, importType: 'API'));
        $legacy = DailyReport::withoutGlobalScopes()->sole();
        $this->assertNull($legacy->dimension->site_id);
        $video = $this->video($context);
        $this->assertSame('2026-09-21', $video->starts_on->toDateString());
        // Deliberately seed an out-of-band historical overlap. This is fixture
        // setup, not an authorized historical correction or production path.
        DB::table('daily_reports')->where('id', $legacy->id)->update(['report_date' => '2026-09-21']);
        $before = DB::table('daily_reports')->get()->toJson();
        $this->finalizeClock();
        Http::fake(['storage.googleapis.com/*' => fn () => Http::response($this->csv())]);
        $job = $this->import($video);
        $this->assertSame(ReportImportStatus::Failed, $job->status);
        $this->assertSame($before, DB::table('daily_reports')->get()->toJson());
        $this->assertSame(0, DailyReport::withoutGlobalScopes()->where('report_source_connection_id', $video->report_source_connection_id)->count());
    }

    public function test_network_mutex_query_precedes_ownership_and_history_reads_for_both_bindings_and_source_import(): void
    {
        [$admin, , , , $gam] = $context = $this->context();
        // This is a deterministic ordering seam on SQLite, not a concurrent
        // MySQL isolation test. Production uses the same lockForUpdate calls.
        $capture = function (callable $action): array {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $action();
                return array_map(fn ($query) => str_replace(['"', '`'], '', strtolower($query['query'])), DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        };
        $assertOrder = function (array $queries, string $label): void {
            $mutex = null;
            $gamRead = null;
            $decisions = [];
            foreach ($queries as $index => $query) {
                if (! str_starts_with(ltrim($query), 'select')) continue;
                if (preg_match('/\bfrom gam_connections\b/', $query) && $mutex === null) $gamRead = $index;
                if (preg_match('/\bfrom site_gam_reporting_network_locks\b/', $query) && $mutex === null) $mutex = $index;
                if (preg_match('/\bfrom (sites|site_gam_report_bindings|site_gam_video_report_bindings|daily_reports|hourly_reports)\b/', $query)) $decisions[] = $index;
            }
            $this->assertNotNull($gamRead, $label.' must read the current GAM row.');
            $this->assertNotNull($mutex, $label.' must acquire the stable network mutex.');
            $this->assertLessThan($mutex, $gamRead, $label.' GAM row read precedes network mutex.');
            $this->assertNotEmpty($decisions, $label.' must exercise ownership/history decisions.');
            foreach ($decisions as $index) $this->assertLessThan($index, $mutex, $label.' network mutex precedes ownership/history reads: '.$queries[$index]);
        };
        $assertOrder($capture(fn () => $this->bind($context)), 'Primary bind');
        $assertOrder($capture(fn () => $this->video($context)), 'Video bind');
        $network = app(ReportingBridge::class)->connectionForGam($gam, $admin);
        $day = CarbonImmutable::parse('2026-09-21');
        $assertOrder($capture(function () use ($network, $gam, $day, $admin): void {
            $job = app(ReportImportService::class)->importRows($network, [[
                'date' => '2026-09-21', 'ad_unit_id' => '99999', 'gam_connection_id' => $gam->id,
                'gross_revenue_minor' => 1000, 'currency' => 'USD',
            ]], ReportGranularity::Daily, ReportFinality::Estimated, $day, $day, $admin, importType: 'API');
            $this->assertCompleted($job);
        }), 'Network source import');
        $this->assertDatabaseCount('site_gam_reporting_network_locks', 1);
    }
}
