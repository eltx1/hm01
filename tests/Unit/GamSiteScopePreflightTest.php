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
            public function call($connection, string $service, string $method, array $payload = []): array
            {
                if ($method === 'getCurrentNetwork') return ['networkCode' => '123', 'currencyCode' => 'USD', 'timeZone' => 'UTC'];
                if ($method === 'runReportJob') {
                    $this->queries[] = $payload['reportJob']['reportQuery'];
                    if ($this->unsupported) throw new \RuntimeException('COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS secret-must-not-escape');
                    return ['id' => '1', 'reportQuery' => ['reportCurrency' => 'USD']];
                }
                if ($method === 'getReportJobStatus') return ['value' => $this->status];
                throw new \RuntimeException('Unexpected operation');
            }
            public function download($connection, string $id): string
            {
                $headers = ['Dimension.DATE', 'Dimension.AD_UNIT_ID', ...($this->missingSite ? [] : ['Dimension.SITE_NAME']), ...array_map(fn ($column) => 'Column.'.$column, \HorusGamSiteScopePreflight::COLUMNS)];
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

    public function test_unsupported_query_is_terminal_without_metric_or_scope_fallback(): void
    {
        $google = $this->google();
        $google->unsupported = true;
        try {
            \HorusGamSiteScopePreflight::run($this->cases(), $google, new GamReportMoneyParser);
            $this->fail('Unsupported report must fail deployment preflight.');
        } catch (\RuntimeException $error) {
            $this->assertSame('COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS', \HorusGamSiteScopePreflight::safeError($error));
            $this->assertCount(1, $google->queries);
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
