<?php

namespace Tests\Unit;

use App\Services\Reporting\Connectors\GamAdUnitReportConnector;
use App\Services\Reporting\GamReportMoneyParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class GamSiteScopePreflightTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('HORUS_GAM_SCOPE_PREFLIGHT_TEST')) define('HORUS_GAM_SCOPE_PREFLIGHT_TEST', true);
        require_once dirname(__DIR__, 2).'/ops/audit/gam-site-scope-preflight.php';
    }

    private function google(): object
    {
        return new class
        {
            public array $queries = [];
            public string $status = 'COMPLETED';
            public bool $unsupported = false;
            public bool $missingSite = false;
            public bool $rejectCombined = false;
            public array $unsupportedColumns = [];
            public array $calls = [];
            public bool $failOther = false;
            public function call($connection, string $service, string $method, array $payload = []): array
            {
                $this->calls[] = $method;
                if ($method === 'getCurrentNetwork') return ['networkCode' => '123', 'currencyCode' => 'USD', 'timeZone' => 'UTC'];
                if ($method === 'runReportJob') {
                    $this->queries[] = $payload['reportJob']['reportQuery'];
                    if ($this->failOther) throw new \RuntimeException('private authorization error');
                    $columns = $payload['reportJob']['reportQuery']['columns'];
                    if ($this->unsupported || ($this->rejectCombined && count($columns) === 9) || array_intersect($columns, $this->unsupportedColumns)) {
                        throw new \RuntimeException('COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS secret-must-not-escape');
                    }
                    return ['id' => (string) count($this->queries), 'reportQuery' => ['reportCurrency' => 'USD']];
                }
                if ($method === 'getReportJobStatus') return ['value' => $this->status];
                throw new \RuntimeException('Unexpected operation');
            }
            public function download($connection, string $id): string
            {
                $this->calls[] = 'download';
                $columns = $this->queries[(int) $id - 1]['columns'];
                $headers = ['Dimension.DATE', 'Dimension.AD_UNIT_ID', ...($this->missingSite ? [] : ['Dimension.SITE_NAME']), ...array_map(fn ($column) => 'Column.'.$column, $columns)];
                return implode(',', $headers)."\n"; // Header-only is still a completed compatible schema.
            }
        };
    }

    private function cases(): array
    {
        return [['connection' => new \stdClass, 'network_code' => '123', 'unit_id' => '456', 'timezone' => 'UTC']];
    }

    public function test_incoming_contract_completes_and_outputs_only_sanitized_compatibility_metadata(): void
    {
        $google = $this->google();
        $result = \HorusGamSiteScopePreflight::run($this->cases(), $google, new GamReportMoneyParser);
        $this->assertTrue($result['compatible']);
        $this->assertSame(1, $result['validated_bindings']);
        $this->assertSame(array_keys(GamAdUnitReportConnector::COLUMNS), \HorusGamSiteScopePreflight::COLUMNS);
        $query = $google->queries[0];
        $this->assertSame(['DATE', 'AD_UNIT_ID', 'SITE_NAME'], $query['dimensions']);
        $this->assertSame('USD', $query['reportCurrency']);
        $this->assertSame('PUBLISHER', $query['timeZoneType']);
        $this->assertSame('WHERE AD_UNIT_ID = :unit', $query['statement']['query']);
        $this->assertArrayNotHasKey('network_code', $result);
        $this->assertArrayNotHasKey('revenue', $result);
    }

    public function test_unsupported_query_stays_terminal_after_bounded_same_scope_diagnostics(): void
    {
        $google = $this->google();
        $google->unsupported = true;
        try {
            \HorusGamSiteScopePreflight::run($this->cases(), $google, new GamReportMoneyParser);
            $this->fail('Unsupported report must fail deployment preflight.');
        } catch (\HorusGamSiteScopeCompatibilityFailure $error) {
            $this->assertSame('COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS', \HorusGamSiteScopePreflight::safeError($error));
            $this->assertCount(11, $google->queries);
            $this->assertCount(10, $error->diagnostics['probes']);
            $this->assertSame(['unsupported'], array_values(array_unique(array_column($error->diagnostics['probes'], 'status'))));
            $this->assertStringNotContainsString('secret-must-not-escape', json_encode($error->diagnostics));
            foreach ($google->queries as $query) {
                $this->assertSame(['DATE', 'AD_UNIT_ID', 'SITE_NAME'], $query['dimensions']);
                $this->assertSame($google->queries[0]['statement'], $query['statement']);
                $this->assertSame('USD', $query['reportCurrency']);
                $this->assertSame('PUBLISHER', $query['timeZoneType']);
                $this->assertSame('FLAT', $query['adUnitView']);
                $this->assertSame($google->queries[0]['startDate'], $query['startDate']);
                $this->assertSame($google->queries[0]['endDate'], $query['endDate']);
            }
        }
    }

    public function test_diagnostics_identify_optional_column_rejection_but_never_accept_a_smaller_contract(): void
    {
        $google = $this->google();
        $google->unsupportedColumns = ['TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS'];
        try {
            \HorusGamSiteScopePreflight::run($this->cases(), $google, new GamReportMoneyParser);
            $this->fail('Compatible individual columns must never allow deployment.');
        } catch (\HorusGamSiteScopeCompatibilityFailure $error) {
            $states = array_column($error->diagnostics['probes'], 'status', 'profile');
            $this->assertSame('compatible', $states['RETAINED_FINANCE']);
            $this->assertSame('compatible', $states['TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE']);
            $this->assertSame('unsupported', $states['TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS']);
            $this->assertCount(11, $google->queries);
        }
    }

    public function test_accepted_but_pending_or_invalid_schema_probe_is_inconclusive_without_polling_retries(): void
    {
        foreach (['pending', 'missing_site'] as $case) {
            $google = $this->google();
            $google->rejectCombined = true;
            $google->status = $case === 'pending' ? 'IN_PROGRESS' : 'COMPLETED';
            $google->missingSite = $case === 'missing_site';
            try {
                \HorusGamSiteScopePreflight::run($this->cases(), $google, new GamReportMoneyParser);
                $this->fail('Diagnostic-only query may not pass the contract.');
            } catch (\HorusGamSiteScopeCompatibilityFailure $error) {
                $this->assertSame(['inconclusive'], array_values(array_unique(array_column($error->diagnostics['probes'], 'status'))));
                $this->assertSame(10, count(array_filter($google->calls, fn ($call) => $call === 'getReportJobStatus')));
                $this->assertCount(11, $google->queries);
            }
        }
    }

    public function test_diagnostic_deadline_bounds_calls_and_other_errors_do_not_trigger_probes(): void
    {
        $google = $this->google();
        $google->unsupported = true;
        $time = 0;
        $clock = static function () use (&$time): float { return $time += 40; };
        try {
            \HorusGamSiteScopePreflight::run($this->cases(), $google, new GamReportMoneyParser, $clock);
            $this->fail('The original rejection must remain terminal.');
        } catch (\HorusGamSiteScopeCompatibilityFailure $error) {
            $this->assertCount(3, $google->queries); // Original plus two bounded diagnostic attempts.
            $this->assertCount(8, array_filter($error->diagnostics['probes'], fn ($probe) => $probe['status'] === 'inconclusive'));
        }

        $google = $this->google();
        $google->failOther = true;
        try {
            \HorusGamSiteScopePreflight::run($this->cases(), $google, new GamReportMoneyParser);
            $this->fail('Unrelated API errors must remain terminal.');
        } catch (\RuntimeException $error) {
            $this->assertNotInstanceOf(\HorusGamSiteScopeCompatibilityFailure::class, $error);
            $this->assertCount(1, $google->queries);
            $this->assertSame('PREFLIGHT_FAILED', \HorusGamSiteScopePreflight::safeError($error));
        }
    }

    public function test_missing_site_header_is_not_a_successful_preflight(): void
    {
        $google = $this->google();
        $google->missingSite = true;
        $this->expectExceptionMessage('INVALID_SCOPED_CSV');
        \HorusGamSiteScopePreflight::run($this->cases(), $google, new GamReportMoneyParser);
    }

    public function test_pending_report_times_out_and_never_claims_compatibility(): void
    {
        $google = $this->google();
        $google->status = 'IN_PROGRESS';
        $time = 0;
        $clock = static function () use (&$time): float { return $time += 40; };
        $this->expectExceptionMessage('PREFLIGHT_TIMEOUT');
        \HorusGamSiteScopePreflight::run($this->cases(), $google, new GamReportMoneyParser, $clock, static fn () => null);
    }

    public function test_failed_google_report_is_terminal(): void
    {
        $google = $this->google();
        $google->status = 'FAILED';
        $this->expectExceptionMessage('GOOGLE_REPORT_FAILED');
        \HorusGamSiteScopePreflight::run($this->cases(), $google, new GamReportMoneyParser);
    }

    public function test_public_repository_gate_runs_before_transfer_and_cannot_invoke_financial_writes(): void
    {
        $root = dirname(__DIR__, 2);
        $workflow = file_get_contents($root.'/.github/workflows/deploy-production.yml');
        $gate = strpos($workflow, 'Verify exact Site GAM reporting compatibility');
        $this->assertLessThan(strpos($workflow, 'Transfer validated release'), $gate);
        $this->assertLessThan(strpos($workflow, 'Deploy atomically'), $gate);
        $this->assertGreaterThan(strpos($workflow, 'Require successful main production validation'), $gate);
        $script = file_get_contents($root.'/ops/audit/gam-site-scope-preflight.php');
        foreach (['ReportImportService', 'SiteGamReportScope', 'importRows(', '->save(', '->update(', '->delete('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $script);
        }
        $this->assertSame('PREFLIGHT_FAILED', \HorusGamSiteScopePreflight::safeError(new \RuntimeException('private signed URL and amounts')));
    }
}
