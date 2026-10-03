<?php

namespace Tests\Unit;

use HorusGamUnfilledProbe as Probe;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GamUnfilledProbeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('HORUS_GAM_UNFILLED_PROBE_LIBRARY_ONLY')) define('HORUS_GAM_UNFILLED_PROBE_LIBRARY_ONLY', true);
        require_once dirname(__DIR__, 2).'/ops/audit/gam-unfilled-probe.php';
    }

    public function test_exact_streamed_diagnostic_payload_has_valid_php_syntax(): void
    {
        $root = dirname(__DIR__, 2);
        $rest = file_get_contents($root.'/ops/audit/gam-rest-unfilled-probe.php');
        $soap = implode("\n", array_slice(explode("\n", file_get_contents($root.'/ops/audit/gam-unfilled-probe.php')), 3));
        $payload = $rest.$soap;
        $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-l']);
        $process->setInput($payload)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('tail -n +4 ops/audit/gam-unfilled-probe.php', file_get_contents($root.'/.github/workflows/deploy-production.yml'));
    }

    private function case(): array
    {
        return ['connection' => new \stdClass, 'network_code' => '123', 'unit_id' => '456',
            'timezone' => 'UTC', 'hostname' => 'exact.example', 'from' => '2026-10-01', 'to' => '2026-10-02'];
    }

    private function csv(string $rows = ''): string
    {
        return 'Dimension.DATE,Dimension.AD_UNIT_ID,Dimension.SITE_NAME,Column.'.Probe::METRIC."\n".$rows;
    }

    private function google(string $csv): object
    {
        return new class($csv) {
            public array $queries = [];
            public string $status = 'COMPLETED';
            public ?string $error = null;
            public string $timezone = 'UTC';
            public function __construct(public string $csv) {}
            public function call($connection, $service, $method, $payload = []): array
            {
                if ($method === 'getCurrentNetwork') return ['networkCode' => '123', 'timeZone' => $this->timezone];
                if ($method === 'runReportJob') {
                    $this->queries[] = $payload['reportJob']['reportQuery'];
                    if ($this->error !== null) throw new RuntimeException($this->error);
                    return ['id' => '12'];
                }
                if ($method === 'getReportJobStatus') return ['value' => $this->status];
                throw new RuntimeException('Unexpected method');
            }
            public function download($connection, $id): string { return $this->csv; }
        };
    }

    public function test_isolated_actual_metric_keeps_exact_dimensions_and_selected_unit(): void
    {
        $google = $this->google($this->csv("2026-10-01,456,exact.example,0\n2026-10-02,456,exact.example,8\n"));
        $private = [];
        $result = Probe::run([$this->case()], $google, static function ($i, $evidence) use (&$private) { $private[] = $evidence; });
        $this->assertSame([Probe::METRIC], $google->queries[0]['columns']);
        $this->assertSame(['DATE', 'AD_UNIT_ID', 'SITE_NAME'], $google->queries[0]['dimensions']);
        $this->assertSame('456', $google->queries[0]['statement']['values'][0]['value']['value']);
        $this->assertSame('PUBLISHER', $google->queries[0]['timeZoneType']);
        $this->assertArrayNotHasKey('reportCurrency', $google->queries[0]);
        $this->assertSame('COMPLETED', $result['probes'][0]['query_status']);
        $this->assertTrue($result['probes'][0]['exact_site_observed']);
        $this->assertTrue($result['probes'][0]['exact_site_days_complete']);
        $this->assertStringNotContainsString('exact.example', json_encode($result));
        $this->assertStringNotContainsString('456', json_encode($result));
        $this->assertSame($google->csv, $private[2]['csv']);
    }

    public function test_accepted_empty_report_proves_schema_only_never_zero_or_attribution(): void
    {
        $result = Probe::run([$this->case()], $this->google($this->csv()), static fn () => null);
        $this->assertSame('COMPLETED', $result['probes'][0]['query_status']);
        $this->assertTrue($result['probes'][0]['valid_csv']);
        foreach (['nonempty_rows', 'exact_site_observed', 'exact_site_days_complete', 'nonmatching_site_observed'] as $flag) {
            $this->assertFalse($result['probes'][0][$flag]);
        }
    }

    public function test_unattributed_and_sibling_rows_never_become_exact_host_zero(): void
    {
        foreach (['(Not applicable)', '', 'www.exact.example', 'sibling.example', 'example'] as $site) {
            $result = Probe::validateCsv($this->csv("2026-10-01,456,$site,19\n"), $this->case());
            $this->assertTrue($result['nonempty_rows']);
            $this->assertTrue($result['nonmatching_site_observed']);
            $this->assertFalse($result['exact_site_observed']);
            $this->assertFalse($result['exact_site_days_complete']);
        }
    }

    public function test_exact_normalized_zero_row_is_evidence_but_missing_days_are_unknown(): void
    {
        $result = Probe::validateCsv($this->csv("2026-10-01,456,EXACT.example.,0\n"), $this->case());
        $this->assertTrue($result['exact_site_observed']);
        $this->assertFalse($result['exact_site_days_complete']);
    }

    public function test_unsupported_metric_is_distinct_from_access_error_and_no_fallback_is_run(): void
    {
        foreach (['COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS private.example' => 'UNSUPPORTED',
            'PERMISSION_DENIED private.example' => 'INCONCLUSIVE'] as $error => $status) {
            $google = $this->google($this->csv()); $google->error = $error;
            $result = Probe::run([$this->case()], $google, static fn () => null);
            $this->assertCount(1, $google->queries);
            $this->assertSame($status, $result['probes'][0]['query_status']);
            $this->assertNull($result['probes'][0]['exact_site_observed']);
            $this->assertStringNotContainsString('private.example', json_encode($result));
        }
    }

    public function test_invalid_csv_never_yields_partial_known_values(): void
    {
        foreach (["2026-10-01,999,exact.example,1\n", "2026-10-03,456,exact.example,1\n",
            "2026-10-01,456,exact.example,-1\n", "2026-10-01,456,exact.example,1.5\n",
            "2026-10-01,456,exact.example,\n", "2026-10-01,456,exact.example,1\n2026-10-01,456,EXACT.EXAMPLE.,1\n"] as $rows) {
            $result = Probe::run([$this->case()], $this->google($this->csv($rows)), static fn () => null);
            $this->assertSame('ACCEPTED', $result['probes'][0]['query_status']);
            $this->assertNull($result['probes'][0]['valid_csv']);
            $this->assertNull($result['probes'][0]['exact_site_observed']);
            $this->assertContains($result['probes'][0]['reason'], ['INVALID_CSV', 'DUPLICATE_ROWS']);
        }
    }

    public function test_missing_and_duplicate_headers_are_rejected(): void
    {
        foreach ([str_replace('Dimension.SITE_NAME,', '', $this->csv()),
            str_replace('Dimension.SITE_NAME', 'Dimension.DATE', $this->csv())] as $csv) {
            try { Probe::validateCsv($csv, $this->case()); $this->fail('Invalid header accepted'); }
            catch (RuntimeException $error) { $this->assertSame('INVALID_CSV', $error->getMessage()); }
        }
    }

    public function test_scope_change_failed_google_job_and_deadline_stay_inconclusive(): void
    {
        $google = $this->google($this->csv()); $google->timezone = 'Europe/London';
        $result = Probe::run([$this->case()], $google, static fn () => null);
        $this->assertSame('NETWORK_METADATA_MISMATCH', $result['probes'][0]['reason']);
        $this->assertSame([], $google->queries);
        $google = $this->google($this->csv()); $google->status = 'FAILED';
        $result = Probe::run([$this->case()], $google, static fn () => null);
        $this->assertSame('GOOGLE_REPORT_FAILED', $result['probes'][0]['reason']);
        $google->status = 'IN_PROGRESS'; $time = 0;
        $clock = static function () use (&$time): float { return $time; };
        $pause = static function () use (&$time): void { $time += 100; };
        $result = Probe::run([$this->case()], $google, static fn () => null, $clock, $pause);
        $this->assertSame('TIME_BUDGET', $result['probes'][0]['reason']);
        $this->assertNull($result['probes'][0]['exact_site_observed']);
    }

    public function test_capacity_limit_and_seven_day_limit_are_enforced(): void
    {
        $case = $this->case(); $case['from'] = '2026-09-01';
        $google = $this->google($this->csv());
        $result = Probe::run([$case], $google, static fn () => null);
        $this->assertSame('INVALID_CASE', $result['probes'][0]['reason']);
        $this->assertSame([], $google->queries);
        $this->expectExceptionMessage('CAPACITY_EXCEEDED');
        Probe::run(array_fill(0, 26, $this->case()), $google, static fn () => null);
    }
}
