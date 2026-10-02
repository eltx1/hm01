<?php

namespace Tests\Unit;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\GamConnection;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Models\SiteGamReportBinding;
use App\Services\Reporting\GamAdUnitReportClient;
use App\Services\Reporting\GamHistoricalCorrectionReport;
use App\Services\Reporting\GamRevenueComparisonService;
use App\Services\Reporting\SiteGamReportMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class GamHistoricalCorrectionReportTest extends TestCase
{
    use RefreshDatabase, InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites;

    // Every identity and report value in this file is synthetic.
    private const CONTEXT = [
        'hostname' => 'news.test.example', 'ad_unit_id' => '456', 'network_code' => '123',
        'network_currency' => 'USD', 'currency' => 'USD', 'timezone' => 'UTC',
        'metric_basis' => 'AD_EXCHANGE_V1', 'from' => '2026-09-01', 'to' => '2026-09-30',
    ];

    private GamAdUnitReportClient $google;
    private GamHistoricalCorrectionReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00 UTC'));
        $this->google = new class extends GamAdUnitReportClient
        {
            public array $calls = [];
            public array $queries = [];
            public array $network = ['networkCode' => '123', 'timeZone' => 'UTC', 'currencyCode' => 'USD'];
            public string $status = 'COMPLETED';
            public string $jobId = '789';
            public ?string $confirmedCurrency = 'USD';
            public bool $unsupportedOptional = false;
            public ?string $runError = null;
            public string $csv = '';
            public int $downloads = 0;

            public function __construct() {}

            public function call(GamConnection $connection, string $service, string $method, array $payload = []): array
            {
                $this->calls[] = $method;
                if ($method === 'getCurrentNetwork') return $this->network;
                if ($method === 'getReportJobStatus') return ['value' => $this->status];
                if ($method === 'runReportJob') {
                    $query = $payload['reportJob']['reportQuery'];
                    $this->queries[] = $query;
                    if ($this->runError !== null) throw new RuntimeException($this->runError);
                    if ($this->unsupportedOptional && array_intersect($query['columns'], array_keys(SiteGamReportMetrics::OPTIONAL_COLUMNS))) {
                        throw new RuntimeException('COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS');
                    }

                    return ['id' => $this->jobId, 'reportQuery' => ['reportCurrency' => $this->confirmedCurrency]];
                }
                throw new RuntimeException('Unexpected synthetic API operation');
            }

            public function download(GamConnection $connection, string $jobId): string
            {
                $this->downloads++;

                return $this->csv;
            }
        };
        $this->app->instance(GamAdUnitReportClient::class, $this->google);
        $this->report = app(GamHistoricalCorrectionReport::class);
    }

    public function test_query_is_fixed_exact_unit_ad_exchange_usd_and_publisher_timezone(): void
    {
        $query = $this->report->query(self::CONTEXT);
        $this->assertSame(['DATE', 'AD_UNIT_ID', 'SITE_NAME'], $query['dimensions']);
        $this->assertSame(array_keys(SiteGamReportMetrics::COLUMNS), $query['columns']);
        $this->assertSame('FLAT', $query['adUnitView']);
        $this->assertSame('PUBLISHER', $query['timeZoneType']);
        $this->assertSame('USD', $query['reportCurrency']);
        $this->assertSame(['year' => 2026, 'month' => 9, 'day' => 1], $query['startDate']);
        $this->assertSame(['query' => 'WHERE AD_UNIT_ID = :unit', 'values' => [
            ['key' => 'unit', 'value' => ['__type' => 'NumberValue', 'value' => '456']],
        ]], $query['statement']);
        $hash = $this->report->queryHash(self::CONTEXT);
        $this->assertSame($hash, $this->report->queryHash(array_reverse(self::CONTEXT, true) + ['captured_at' => 'irrelevant']));
        $this->assertNotSame($hash, $this->report->queryHash(array_replace(self::CONTEXT, ['ad_unit_id' => '457'])));
        $this->assertNotSame($hash, $this->report->queryHash(array_replace(self::CONTEXT, ['to' => '2026-09-29'])));
        $this->assertSame([], $this->google->calls);
    }

    public function test_full_metrics_preserve_integer_micros_and_exact_normalized_site_only(): void
    {
        $rows = [$this->row('2026-09-02', 'NEWS.TEST.EXAMPLE.', '-5000'), $this->row()];
        foreach (['test.example', 'other.test.example', 'www.news.test.example', 'child.news.test.example', '(unknown)', 'https://news.test.example'] as $host) {
            $rows[] = $this->row('2026-09-01', $host);
        }
        $result = $this->parse($rows);
        $this->assertSame(['2026-09-01', '2026-09-02'], array_keys($result['days']));
        $this->assertSame([
            'ad_requests' => 15, 'matched_requests' => 10, 'impressions' => 8, 'clicks' => 2,
            'revenue_micros' => 15000, 'active_view_viewable_impressions' => 4,
            'active_view_measurable_impressions' => 6, 'gross_revenue_minor' => 2, 'unfilled_impressions' => null,
        ], $result['days']['2026-09-01']);
        $this->assertSame(-5000, $result['days']['2026-09-02']['revenue_micros']);
        $this->assertSame(-1, $result['days']['2026-09-02']['gross_revenue_minor']);
        $this->assertSame(8, $result['source_rows']);
        $this->assertSame(6, $result['excluded_site_rows']);
        $this->assertTrue($result['exact_site_observed']);
        $this->assertStringNotContainsString('other.test.example', json_encode($result));
    }

    public function test_optional_counters_stay_null_and_absent_days_are_never_zero_filled(): void
    {
        $result = $this->parse([array_slice($this->row(), 0, 8)], false);
        $this->assertNull($result['days']['2026-09-01']['active_view_viewable_impressions']);
        $this->assertNull($result['days']['2026-09-01']['active_view_measurable_impressions']);
        $this->assertNull($result['days']['2026-09-01']['unfilled_impressions']);
        $this->assertCount(1, $result['days']);
        $absent = $this->parse([$this->row('2026-09-01', 'other.test.example', '0')]);
        $this->assertSame([], $absent['days']);
        $this->assertFalse($absent['exact_site_observed']);
        $this->assertSame([], $this->parse([])['days']);
        $zero = $this->parse([$this->row('2026-09-01', 'news.test.example', '0')]);
        $this->assertTrue($zero['exact_site_observed']);
        $this->assertSame(0, $zero['days']['2026-09-01']['gross_revenue_minor']);
    }

    public function test_descriptive_name_and_bom_are_allowed_without_changing_scope_or_output(): void
    {
        $csv = "\xEF\xBB\xBF".$this->csv([
            [...$this->row(), "Synthetic, descriptive \"unit\"\nname"],
        ], [...$this->headers(), 'Dimension.AD_UNIT_NAME']);
        $result = $this->report->parse($csv, self::CONTEXT, null);
        $this->assertSame($this->parse([$this->row()]), $result);
        $this->assertStringNotContainsString('Synthetic', json_encode($result));
    }

    #[DataProvider('roundingCases')]
    public function test_signed_daily_rounding(string $micros, int $minor): void
    {
        $result = $this->parse([$this->row('2026-09-01', 'news.test.example', $micros)]);
        $this->assertSame((int) $micros, $result['days']['2026-09-01']['revenue_micros']);
        $this->assertSame($minor, $result['days']['2026-09-01']['gross_revenue_minor']);
    }

    public static function roundingCases(): array
    {
        return [['0', 0], ['4999', 0], ['5000', 1], ['14999', 1], ['15000', 2],
            ['-4999', 0], ['-5000', -1], ['-14999', -1], ['-15000', -2], ['999999999999999', 100000000000]];
    }

    #[DataProvider('invalidValues')]
    public function test_malformed_or_out_of_scope_rows_fail_closed(int $index, string $value, string $error): void
    {
        $row = $this->row();
        $row[$index] = $value;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($error);
        $this->parse([$row]);
    }

    public static function invalidValues(): array
    {
        return [
            [0, '2026-09-31', 'OUT_OF_SCOPE_DATE'], [0, '2026-08-31', 'OUT_OF_SCOPE_DATE'],
            [0, '2026-09-1', 'OUT_OF_SCOPE_DATE'], [1, '457', 'OUT_OF_SCOPE_UNIT'],
            [3, '-1', 'INVALID_COUNTER'], [4, '1.0', 'INVALID_COUNTER'],
            [5, '9999999999999999', 'INVALID_COUNTER'], [6, '', 'INVALID_COUNTER'],
            [8, '-1', 'INVALID_COUNTER'], [9, '1e2', 'INVALID_COUNTER'],
        ];
    }

    public function test_missing_unknown_duplicate_and_total_metric_headers_are_rejected(): void
    {
        $headers = $this->headers();
        foreach ([array_slice($headers, 1), array_values(array_diff($headers, ['Column.AD_EXCHANGE_TOTAL_REQUESTS'])),
            array_values(array_diff($headers, ['Column.AD_EXCHANGE_ACTIVE_VIEW_VIEWABLE_IMPRESSIONS'])),
            array_values(array_diff($headers, ['Column.AD_EXCHANGE_ACTIVE_VIEW_MEASURABLE_IMPRESSIONS'])),
            [...$headers, 'Column.UNRELATED'], [...$headers, $headers[0]], [...$headers, 'Dimension.COUNTRY_NAME'],
            [...$headers, 'Column.TOTAL_LINE_ITEM_LEVEL_REVENUE'],
            [...$headers, 'Dimension.AD_UNIT_NAME', 'Dimension.AD_UNIT_NAME']] as $invalid) {
            try {
                $this->report->parse($this->csv([], $invalid), self::CONTEXT, null);
                $this->fail('Invalid headers were accepted.');
            } catch (RuntimeException $error) {
                $this->assertSame('INVALID_CSV_HEADERS', $error->getMessage());
            }
        }
    }

    public function test_active_view_counters_must_obey_importer_invariant_even_on_excluded_rows(): void
    {
        $row = $this->row('2026-09-01', 'other.test.example');
        $row[8] = '7';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_ACTIVE_VIEW_COUNTERS');
        $this->parse([$row]);
    }

    public function test_duplicate_normalized_grains_are_rejected_even_when_excluded(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DUPLICATE_CSV_ROW');
        $this->parse([$this->row('2026-09-01', 'other.test.example'), $this->row('2026-09-01', 'OTHER.TEST.EXAMPLE.')]);
    }

    public function test_malformed_quoting_is_rejected_even_in_unused_descriptive_name(): void
    {
        $csv = $this->csv([], [...$this->headers(), 'Dimension.AD_UNIT_NAME']);
        $csv .= implode(',', $this->row()).',"unterminated';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_CSV_ROW');
        $this->report->parse($csv, self::CONTEXT, null);
    }

    public function test_wrong_row_width_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INVALID_CSV_ROW');
        $this->parse([array_slice($this->row(), 0, -1)]);
    }

    public function test_excluded_rows_still_need_valid_metrics_and_proven_currency(): void
    {
        $this->expectException(RuntimeException::class);
        $this->report->parse($this->csv([$this->row('2026-09-01', 'other.test.example')]),
            array_replace(self::CONTEXT, ['network_currency' => 'AED']), null);
    }

    public function test_foreign_network_money_needs_its_own_job_confirmation_or_usd_marker(): void
    {
        $context = array_replace(self::CONTEXT, ['network_currency' => 'AED']);
        $marked = $this->report->parse($this->csv([$this->row('2026-09-01', 'news.test.example', 'USD 15000')]), $context, null);
        $confirmed = $this->report->parse($this->csv([$this->row()]), $context, 'USD');
        $this->assertSame($marked, $confirmed);
        $this->expectException(RuntimeException::class);
        $this->report->parse($this->csv([$this->row('2026-09-01', 'news.test.example', 'AED 15000')]), $context, 'USD');
    }

    public function test_different_metric_basis_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('METRIC_BASIS_CHANGED');
        $this->report->parse($this->csv([]), array_replace(self::CONTEXT, ['metric_basis' => 'LEGACY_TOTAL_UNVERSIONED']), null);
    }

    public function test_private_start_and_nonblocking_poll_do_not_write_reporting_or_financial_state(): void
    {
        $context = $this->boundContext();
        $before = $this->state();
        $this->google->csv = $this->csv([$this->row()]);
        $job = $this->report->start($context);
        $this->assertSame(['id' => '789', 'status' => 'PENDING', 'confirmed_currency' => 'USD',
            'next_poll_at' => now()->addSeconds(10)->timestamp, 'polls' => 0], $job);
        $calls = $this->google->calls;
        $this->assertSame($job, $this->report->poll($context, $job));
        $this->assertSame($calls, $this->google->calls);
        $this->travel(10)->seconds();
        $this->google->status = 'IN_PROGRESS';
        $job = $this->report->poll($context, $job);
        $this->assertSame('PENDING', $job['status']);
        $this->assertSame(1, $job['polls']);
        $this->assertSame(0, $this->google->downloads);
        $this->travel(15)->seconds();
        $this->google->status = 'COMPLETED';
        $job = $this->report->poll($context, $job);
        $this->assertSame('COMPLETED', $job['status']);
        $this->assertSame(2, $job['polls']);
        $this->assertSame($this->parse([$this->row()]), $job['result']);
        $this->assertSame(now()->toIso8601String(), $job['completed_at']);
        $calls = $this->google->calls;
        $this->assertSame($job, $this->report->poll($context, $job));
        $this->assertSame($calls, $this->google->calls);
        $this->assertSame(1, $this->google->downloads);
        $this->assertSame($before, $this->state());
    }

    public function test_optional_fallback_retains_all_five_core_columns_and_its_own_currency_evidence(): void
    {
        $context = $this->boundContext();
        $this->google->unsupportedOptional = true;
        $this->google->confirmedCurrency = null;
        $job = $this->report->start($context);
        $this->assertSame(array_keys(SiteGamReportMetrics::COLUMNS), $this->google->queries[0]['columns']);
        $this->assertSame(array_keys(SiteGamReportMetrics::CORE_COLUMNS), $this->google->queries[1]['columns']);
        $this->assertNull($job['confirmed_currency']);
        $this->google->csv = $this->csv([array_slice($this->row(), 0, 8)], $this->headers(false));
        $this->travel(10)->seconds();
        $job = $this->report->poll($context, $job);
        $this->assertNull($job['result']['days']['2026-09-01']['active_view_viewable_impressions']);
    }

    public function test_arbitrary_google_failure_is_never_retried_as_optional_fallback(): void
    {
        $context = $this->boundContext();
        $this->google->runError = 'PERMISSION_DENIED';
        try {
            $this->report->start($context);
            $this->fail('A failed job was accepted.');
        } catch (RuntimeException $error) {
            $this->assertSame('PERMISSION_DENIED', $error->getMessage());
            $this->assertCount(1, $this->google->queries);
        }
    }

    #[DataProvider('changedNetworks')]
    public function test_network_changes_block_start_and_poll(string $field, string $changed): void
    {
        $context = $this->boundContext();
        $job = $this->report->start($context);
        $this->google->network[$field] = $changed;
        foreach (['start', 'poll'] as $action) {
            $this->travel(15)->seconds();
            try {
                $action === 'start' ? $this->report->start($context) : $this->report->poll($context, $job);
                $this->fail('A changed network was accepted.');
            } catch (RuntimeException $error) {
                $this->assertSame('NETWORK_IDENTITY_CHANGED', $error->getMessage());
            }
        }
        $this->assertCount(1, $this->google->queries);
        $this->assertSame(0, $this->google->downloads);
    }

    public static function changedNetworks(): array
    {
        return [['networkCode', '999'], ['timeZone', 'Asia/Dubai'], ['currencyCode', 'AED']];
    }

    public function test_poll_limit_stops_before_any_google_request(): void
    {
        $job = ['id' => '789', 'status' => 'PENDING', 'confirmed_currency' => null,
            'next_poll_at' => now()->timestamp, 'polls' => 60];
        try {
            $this->report->poll(self::CONTEXT, $job);
            $this->fail('The poll limit was exceeded.');
        } catch (RuntimeException $error) {
            $this->assertSame('POLL_LIMIT_REACHED', $error->getMessage());
            $this->assertSame([], $this->google->calls);
        }
    }

    private function boundContext(): array
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user);
        $site = $this->makeSiteFor($publisher, $user, ['display_name' => 'Synthetic News', 'primary_domain' => 'news.test.example']);
        $gam = $this->makeGamConnection($admin->organization, $admin, ['network_code' => '123']);
        $source = ReportSource::query()->create(['code' => 'GAM_AD_UNIT', 'name' => 'Synthetic GAM', 'is_enabled' => true]);
        $bindingId = (string) Str::ulid();
        $connection = ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $user->organization_id, 'report_source_id' => $source->id,
            'name' => 'Synthetic site source', 'connection_type' => 'SITE_GAM_AD_UNIT', 'connection_id' => $bindingId,
            'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'ACTIVE', 'is_enabled' => true,
            'configuration' => ['google_jobs' => ['synthetic-checkpoint' => ['id' => '999']]],
        ]);
        SiteGamReportBinding::withoutGlobalScopes()->create([
            'id' => $bindingId, 'organization_id' => $user->organization_id, 'site_id' => $site->id,
            'gam_connection_id' => $gam->id, 'report_source_connection_id' => $connection->id,
            'active_site_id' => $site->id, 'active_unit_key' => '123:456', 'network_code' => '123',
            'ad_unit_id' => '456', 'ad_unit_name' => 'Synthetic unit', 'ad_unit_code' => 'synthetic', 'starts_on' => '2026-09-01',
        ]);

        return app(GamRevenueComparisonService::class)->context($site, self::CONTEXT['from'], self::CONTEXT['to']);
    }

    private function state(): array
    {
        $tables = ['sites', 'report_source_connections', 'site_gam_report_bindings', 'report_import_jobs',
            'daily_reports', 'hourly_reports', 'monthly_reports', 'financial_periods', 'revenue_rules',
            'revenue_rule_versions', 'revenue_adjustments', 'publisher_statements', 'publisher_payments'];

        return collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function headers(bool $optional = true): array
    {
        return ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Dimension.SITE_NAME',
            ...array_map(fn ($column) => 'Column.'.$column, array_keys($optional ? SiteGamReportMetrics::COLUMNS : SiteGamReportMetrics::CORE_COLUMNS))];
    }

    private function row(string $date = '2026-09-01', string $host = 'news.test.example', string $micros = '15000'): array
    {
        return [$date, '456', $host, '15', '10', '8', '2', $micros, '4', '6'];
    }

    private function parse(array $rows, bool $optional = true): array
    {
        return $this->report->parse($this->csv($rows, $this->headers($optional)), self::CONTEXT, null);
    }

    private function csv(array $rows, ?array $headers = null): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $headers ?? $this->headers(), escape: '');
        foreach ($rows as $row) fputcsv($stream, $row, escape: '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }
}
