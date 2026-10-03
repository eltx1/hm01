<?php

namespace Tests\Unit;

use DateTimeImmutable;
use HorusGamRestUnfilledProbe;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../ops/audit/gam-rest-unfilled-probe.php';

class GamRestUnfilledProbeTest extends TestCase
{
    private function binding(): array
    {
        return ['connection' => 'existing-connection', 'network_code' => '12345', 'unit_id' => '67890',
            'hostname' => 'registered.example', 'timezone' => 'UTC', 'from' => '2026-09-26', 'to' => '2026-10-02'];
    }

    private function now(): float
    {
        return (float) (new DateTimeImmutable('2026-10-03T12:00:00Z'))->getTimestamp();
    }

    private function report(?array $definition = null): array
    {
        return ['name' => 'networks/12345/reports/100', 'displayName' => 'Unrelated title', 'visibility' => 'HIDDEN',
            'reportDefinition' => $definition ?? HorusGamRestUnfilledProbe::definition($this->binding())];
    }

    private function operation(bool $done = true): array
    {
        return ['name' => 'networks/12345/operations/reports/runs/200', 'done' => $done,
            'metadata' => ['report' => 'networks/12345/reports/100']] + ($done ? [
                'response' => ['reportResult' => 'networks/12345/reports/100/results/300'],
            ] : []);
    }

    private function row(string $day = '2026-09-26', string $count = '0'): array
    {
        return ['dimensionValues' => [['stringValue' => $day], ['intValue' => '67890'], ['stringValue' => 'registered.example']],
            'metricValueGroups' => [['primaryValues' => [['intValue' => $count]]]]];
    }

    private function rows(array $rows): array
    {
        return ['rows' => $rows, 'totalRowCount' => count($rows),
            'dateRanges' => [HorusGamRestUnfilledProbe::definition($this->binding())['dateRange']['fixed']]];
    }

    private function execute(?callable $override = null, bool $dryRun = false, ?array $cases = null, ?callable $clock = null, ?callable $pause = null): array
    {
        $calls = []; $evidence = [];
        $request = function (array $case, string $verb, string $path, array $payload) use (&$calls, $override): array {
            $calls[] = [$verb, $path, $payload];
            $replacement = $override ? $override($verb, $path, $payload, count($calls)) : null;
            if ($replacement !== null) return $replacement;
            return match ($verb.' '.$path) {
                'GET networks/12345' => ['name' => 'networks/12345', 'networkCode' => '12345', 'timeZone' => 'UTC'],
                'GET networks/12345/reports' => ['reports' => [$this->report()]],
                'GET networks/12345/reports/100' => $this->report(),
                'POST networks/12345/reports' => ['name' => 'networks/12345/reports/100'] + $payload,
                'POST networks/12345/reports/100:run', 'GET networks/12345/operations/reports/runs/200' => $this->operation(),
                'GET networks/12345/reports/100/results/300:fetchRows' => $this->rows([$this->row()]),
                default => throw new RuntimeException('UNEXPECTED_REQUEST'),
            };
        };
        $save = static function (int $index, array $value) use (&$evidence): void { $evidence[] = [$index, $value]; };
        $result = HorusGamRestUnfilledProbe::run($cases ?? [$this->binding()], $request, $save,
            $clock ?? fn (): float => $this->now(), $pause ?? static fn (int $seconds): int => $seconds, $dryRun);
        return [$result, $calls, $evidence];
    }

    public function test_exact_saved_definition_is_reused_and_real_zero_rows_establish_scope(): void
    {
        [$result, $calls, $evidence] = $this->execute();
        $probe = $result['probes'][0];
        $this->assertSame('COMPLETED', $probe['query_status']);
        $this->assertSame('REUSED', $probe['definition_status']);
        $this->assertTrue($probe['valid_rows']);
        $this->assertTrue($probe['exact_site_observed']);
        $this->assertFalse($probe['exact_site_days_complete']);
        $this->assertSame(['POST networks/12345/reports/100:run'], array_values(array_map(
            static fn (array $call): string => $call[0].' '.$call[1], array_filter($calls, static fn (array $call): bool => $call[0] === 'POST'))));
        $this->assertSame([], $calls[3][2]);
        $this->assertNotEmpty($evidence);
        $public = json_encode($result);
        foreach (['12345', '67890', 'registered.example', 'results/300', 'existing-connection'] as $private) $this->assertStringNotContainsString($private, $public);
    }

    public function test_definition_has_only_the_true_metric_and_two_exact_filters(): void
    {
        $definition = HorusGamRestUnfilledProbe::definition($this->binding());
        $this->assertSame(['DATE', 'AD_UNIT_ID', 'SITE'], $definition['dimensions']);
        $this->assertSame(['UNFILLED_IMPRESSIONS'], $definition['metrics']);
        $this->assertFalse($definition['expandedCompatibility']);
        $this->assertSame('PUBLISHER', $definition['timeZoneSource']);
        $this->assertSame(['startDate' => ['year' => 2026, 'month' => 9, 'day' => 26], 'endDate' => ['year' => 2026, 'month' => 10, 'day' => 2]], $definition['dateRange']['fixed']);
        $this->assertSame([
            ['fieldFilter' => ['field' => ['dimension' => 'AD_UNIT_ID'], 'operation' => 'IN', 'values' => [['intValue' => '67890']]]],
            ['fieldFilter' => ['field' => ['dimension' => 'SITE'], 'operation' => 'IN', 'values' => [['stringValue' => 'registered.example']]]],
        ], $definition['filters'][0]['andFilter']['filters']);
    }

    public function test_creation_is_hidden_unscheduled_and_only_after_exhausted_listing(): void
    {
        [$result, $calls] = $this->execute(fn ($verb, $path) => $verb === 'GET' && $path === 'networks/12345/reports' ? ['reports' => []] : null);
        $this->assertSame('CREATED', $result['probes'][0]['definition_status']);
        $this->assertSame('COMPLETED', $result['probes'][0]['query_status']);
        $this->assertSame('GET', $calls[1][0]);
        $this->assertSame(['pageSize' => 1000], $calls[1][2]);
        $this->assertSame('POST', $calls[2][0]);
        $this->assertSame(['displayName', 'visibility', 'reportDefinition'], array_keys($calls[2][2]));
        $this->assertSame('HIDDEN', $calls[2][2]['visibility']);
        $this->assertTrue($result['probes'][0]['list_exhausted']);
    }

    public function test_dry_run_is_default_and_never_creates_or_runs_a_report(): void
    {
        $calls = [];
        $request = function ($case, $verb, $path, $payload) use (&$calls): array {
            $calls[] = $verb;
            return $path === 'networks/12345'
                ? ['name' => $path, 'networkCode' => '12345', 'timeZone' => 'UTC'] : ['reports' => []];
        };
        $result = HorusGamRestUnfilledProbe::run([$this->binding()], $request, static function (): void {}, fn () => $this->now());
        $this->assertSame(['GET', 'GET'], $calls);
        $this->assertTrue($result['dry_run']);
        $this->assertSame('WOULD_CREATE', $result['probes'][0]['definition_status']);
        $this->assertSame('DRY_RUN', $result['probes'][0]['query_status']);
    }

    public function test_reuse_compares_entire_definition_not_its_title(): void
    {
        $expected = HorusGamRestUnfilledProbe::definition($this->binding());
        $variants = [];
        foreach (['dimensions' => ['DATE', 'AD_UNIT_ID'], 'metrics' => ['UNMATCHED_AD_REQUESTS'],
            'filters' => [], 'timeZoneSource' => 'AD_EXCHANGE', 'expandedCompatibility' => true,
            'dateRange' => ['relative' => 'LAST_7_DAYS'], 'comparisonDateRange' => ['relative' => 'PREVIOUS_PERIOD'],
            'reportType' => 'AD_SPEED', 'sorts' => [['descending' => true]]] as $field => $replacement) {
            $variants[] = array_replace($expected, [$field => $replacement]);
        }
        foreach ($variants as $definition) {
            [$result] = $this->execute(fn ($verb, $path) => $path === 'networks/12345/reports' ? ['reports' => [$this->report($definition)]] : null, true);
            $this->assertSame('WOULD_CREATE', $result['probes'][0]['definition_status']);
        }
        $defaulted = $expected;
        unset($defaulted['expandedCompatibility'], $defaulted['timeZoneSource']);
        $defaulted['flags'] = []; $defaulted['timeZone'] = '';
        [$result] = $this->execute(fn ($verb, $path) => $path === 'networks/12345/reports' ? ['reports' => [$this->report($defaulted)]] : null, true);
        $this->assertSame('REUSED', $result['probes'][0]['definition_status']);
    }

    public function test_protobuf_filter_defaults_are_equivalent_when_omitted_or_explicit(): void
    {
        $definition = HorusGamRestUnfilledProbe::definition($this->binding());
        $variants = [];
        foreach ([false, true] as $explicitDefaults) {
            $variant = $definition;
            foreach ($variant['filters'][0]['andFilter']['filters'] as &$filter) {
                if ($explicitDefaults) {
                    $filter['fieldFilter']['metricValueType'] = 'PRIMARY';
                    $filter['fieldFilter']['timePeriodIndex'] = 0;
                } else {
                    unset($filter['fieldFilter']['operation']);
                }
            }
            unset($filter);
            $variants[] = $variant;
        }
        foreach ($variants as $variant) {
            [$result, $calls] = $this->execute(fn ($verb, $path) => $path === 'networks/12345/reports'
                ? ['reports' => [$this->report($variant)]] : ($path === 'networks/12345/reports/100' ? $this->report($variant) : null));
            $this->assertSame('REUSED', $result['probes'][0]['definition_status']);
            $this->assertSame('COMPLETED', $result['probes'][0]['query_status']);
            $creates = array_filter($calls, static fn (array $call): bool => $call[0] === 'POST' && $call[1] === 'networks/12345/reports');
            $this->assertSame([], $creates);
        }
    }

    public function test_incomplete_report_listing_never_creates_a_duplicate(): void
    {
        [$result, $calls] = $this->execute(fn ($verb, $path) => $path === 'networks/12345/reports' ? ['reports' => [], 'nextPageToken' => 'more'] : null);
        $this->assertSame('LIST_INCOMPLETE', $result['probes'][0]['reason']);
        $this->assertFalse($result['probes'][0]['list_exhausted']);
        $this->assertCount(3, $calls);
        $this->assertSame(['GET'], array_values(array_unique(array_column($calls, 0))));
    }

    public function test_second_listing_page_can_reuse_exact_report(): void
    {
        [$result, $calls] = $this->execute(fn ($verb, $path, $payload) => $path === 'networks/12345/reports' && ! isset($payload['pageToken'])
            ? ['reports' => [], 'nextPageToken' => 'second-page'] : null);
        $this->assertSame('COMPLETED', $result['probes'][0]['query_status']);
        $this->assertSame('REUSED', $result['probes'][0]['definition_status']);
        $this->assertSame('second-page', $calls[2][2]['pageToken']);
    }

    public function test_network_and_current_seven_day_scope_are_validated_before_writes(): void
    {
        [$result, $calls] = $this->execute(fn ($verb, $path) => $path === 'networks/12345'
            ? ['name' => $path, 'networkCode' => '12345', 'timeZone' => 'America/New_York'] : null);
        $this->assertSame('NETWORK_METADATA_MISMATCH', $result['probes'][0]['reason']);
        $this->assertCount(1, $calls);
        foreach (['from' => '2026-09-27', 'to' => '2026-10-03', 'unit_id' => '0', 'hostname' => 'www.registered.example.', 'timezone' => 'invalid'] as $field => $value) {
            [$result, $calls] = $this->execute(cases: [array_replace($this->binding(), [$field => $value])]);
            $this->assertSame('INVALID_CASE', $result['probes'][0]['reason']);
            $this->assertSame([], $calls);
        }
    }

    public function test_reusable_report_is_rechecked_before_run(): void
    {
        $changed = $this->report(); $changed['reportDefinition']['filters'] = [];
        [$result, $calls] = $this->execute(fn ($verb, $path) => $path === 'networks/12345/reports/100' ? $changed : null);
        $this->assertSame('REPORT_DEFINITION_MISMATCH', $result['probes'][0]['reason']);
        $this->assertSame(['GET'], array_values(array_unique(array_column($calls, 0))));
    }

    public function test_untrusted_resource_names_never_become_requests(): void
    {
        foreach (['networks/999/reports/100', 'https://evil.example/report', 'networks/12345/reports/../999'] as $name) {
            [$result, $calls] = $this->execute(fn ($verb, $path) => $path === 'networks/12345/reports'
                ? ['reports' => [array_replace($this->report(), ['name' => $name])]] : null);
            $this->assertSame('INVALID_REPORT', $result['probes'][0]['reason']);
            $this->assertCount(2, $calls);
        }
        [$result, $calls] = $this->execute(fn ($verb, $path) => str_ends_with($path, ':run')
            ? array_replace($this->operation(false), ['name' => 'networks/999/operations/reports/runs/200']) : null);
        $this->assertSame('INVALID_OPERATION', $result['probes'][0]['reason']);
        $this->assertCount(4, $calls);
        [$result, $calls] = $this->execute(fn ($verb, $path) => str_ends_with($path, ':run')
            ? array_replace($this->operation(), ['response' => ['reportResult' => 'networks/12345/reports/999/results/300']]) : null);
        $this->assertSame('INVALID_RESULT', $result['probes'][0]['reason']);
        $this->assertCount(4, $calls);
    }

    public function test_completed_operation_requires_explicit_result_resource(): void
    {
        $operation = $this->operation(); unset($operation['response']);
        [$result] = $this->execute(fn ($verb, $path) => str_ends_with($path, ':run') ? $operation : null);
        $this->assertSame('INVALID_RESULT', $result['probes'][0]['reason']);
        $this->assertNull($result['probes'][0]['exact_site_observed']);
    }

    public function test_empty_results_do_not_prove_zero_or_exact_site_coverage(): void
    {
        [$result] = $this->execute(fn ($verb, $path) => str_ends_with($path, ':fetchRows') ? $this->rows([]) : null);
        $probe = $result['probes'][0];
        $this->assertSame('COMPLETED', $probe['query_status']);
        $this->assertSame('NO_ROWS', $probe['reason']);
        $this->assertTrue($probe['valid_rows']);
        $this->assertFalse($probe['nonempty_rows']);
        $this->assertFalse($probe['exact_site_observed']);
        $this->assertFalse($probe['exact_site_days_complete']);
    }

    public function test_all_seven_explicit_zero_rows_establish_complete_day_coverage(): void
    {
        $rows = [];
        for ($day = 0; $day < 7; $day++) $rows[] = $this->row((new DateTimeImmutable('2026-09-26'))->modify('+'.$day.' days')->format('Y-m-d'));
        $result = HorusGamRestUnfilledProbe::validateRows($rows, $this->binding());
        $this->assertTrue($result['exact_site_days_complete']);
        $this->assertTrue($result['exact_site_observed']);
    }

    public function test_every_row_and_metric_must_match_exact_scope_and_integer_schema(): void
    {
        $badRows = [];
        $badRows[] = $this->row('2026-09-25');
        foreach (['', '-1', '1.2', '1e2', '9223372036854775808'] as $count) $badRows[] = $this->row(count: $count);
        $row = $this->row(); $row['dimensionValues'][1]['intValue'] = '1'; $badRows[] = $row;
        $row = $this->row(); $row['dimensionValues'][2]['stringValue'] = 'www.registered.example'; $badRows[] = $row;
        $row = $this->row(); $row['dimensionValues'][2]['stringValue'] = ''; $badRows[] = $row;
        $row = $this->row(); $row['dimensionValues'][0] = ['intValue' => '20260926']; $badRows[] = $row;
        $row = $this->row(); $row['metricValueGroups'][0]['primaryValues'] = []; $badRows[] = $row;
        $row = $this->row(); $row['metricValueGroups'][0]['primaryValues'][0] = ['doubleValue' => 0.0]; $badRows[] = $row;
        $row = $this->row(); $row['metricValueGroups'][0]['primaryValues'][0] = ['intValue' => 0]; $badRows[] = $row;
        foreach ($badRows as $badRow) {
            [$result] = $this->execute(fn ($verb, $path) => str_ends_with($path, ':fetchRows') ? $this->rows([$badRow]) : null);
            $this->assertSame('INVALID_ROWS', $result['probes'][0]['reason']);
            $this->assertNull($result['probes'][0]['exact_site_observed']);
        }
    }

    public function test_duplicate_rows_and_result_date_range_mismatch_are_rejected(): void
    {
        [$result] = $this->execute(fn ($verb, $path) => str_ends_with($path, ':fetchRows') ? $this->rows([$this->row(), $this->row()]) : null);
        $this->assertSame('DUPLICATE_ROWS', $result['probes'][0]['reason']);
        $response = $this->rows([$this->row()]); $response['dateRanges'][0]['startDate']['day'] = 25;
        [$result] = $this->execute(fn ($verb, $path) => str_ends_with($path, ':fetchRows') ? $response : null);
        $this->assertSame('RESULT_DATE_RANGE_MISMATCH', $result['probes'][0]['reason']);
    }

    public function test_paginated_rows_are_checked_before_proof_is_published(): void
    {
        [$result, $calls] = $this->execute(function ($verb, $path, $payload) {
            if (! str_ends_with($path, ':fetchRows')) return null;
            return isset($payload['pageToken']) ? ['rows' => [$this->row('2026-09-27')]]
                : array_replace($this->rows([$this->row()]), ['totalRowCount' => 2, 'nextPageToken' => 'next-rows']);
        });
        $this->assertSame('COMPLETED', $result['probes'][0]['query_status']);
        $this->assertSame('next-rows', $calls[5][2]['pageToken']);
        [$result] = $this->execute(fn ($verb, $path) => str_ends_with($path, ':fetchRows')
            ? array_replace($this->rows([$this->row()]), ['nextPageToken' => 'never-done']) : null);
        $this->assertSame('ROWS_INCOMPLETE', $result['probes'][0]['reason']);
        $this->assertNull($result['probes'][0]['valid_rows']);
    }

    public function test_access_errors_are_distinct_from_incompatibility_and_other_invalid_requests(): void
    {
        $cases = [
            [['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'private 67890'], 'ACCESS_BLOCKED', 'REST_ACCESS_BLOCKED'],
            [['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Metrics and dimensions are incompatible'], 'INCOMPATIBLE', 'REST_INCOMPATIBLE'],
            [['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Unknown request field'], 'INCONCLUSIVE', 'REST_INVALID_ARGUMENT'],
            [['code' => 503, 'status' => 'UNAVAILABLE'], 'INCONCLUSIVE', 'REST_UNAVAILABLE'],
        ];
        foreach ($cases as [$error, $status, $reason]) {
            [$result] = $this->execute(fn () => ['error' => $error]);
            $this->assertSame($status, $result['probes'][0]['query_status']);
            $this->assertSame($reason, $result['probes'][0]['reason']);
            $this->assertNull($result['probes'][0]['exact_site_observed']);
            $this->assertStringNotContainsString('67890', json_encode($result));
        }
    }

    public function test_polling_is_bounded_even_when_injected_clock_does_not_advance(): void
    {
        $delays = [];
        [$result, $calls] = $this->execute(fn ($verb, $path) => str_contains($path, 'operations/') || str_ends_with($path, ':run') ? $this->operation(false) : null,
            pause: static function (int $seconds) use (&$delays): void { $delays[] = $seconds; });
        $this->assertSame('POLL_LIMIT', $result['probes'][0]['reason']);
        $this->assertSame([5, 10, 20, 20, 20, 20, 20, 20], $delays);
        $this->assertCount(12, $calls);
        $this->assertNull($result['probes'][0]['exact_site_observed']);
    }

    public function test_shared_time_budget_reserves_time_before_every_request(): void
    {
        $now = $this->now();
        [$result, $calls] = $this->execute(clock: static function () use (&$now): float { $value = $now; $now += 60; return $value; });
        $this->assertSame('TIME_BUDGET', $result['probes'][0]['reason']);
        $this->assertCount(2, $calls);
    }

    public function test_capacity_cap_is_enforced_before_any_request(): void
    {
        $this->expectExceptionMessage('CAPACITY_EXCEEDED');
        $this->execute(cases: array_fill(0, 26, $this->binding()));
    }

    public function test_unknown_exception_text_is_never_public(): void
    {
        [$result, $calls] = $this->execute(static function (): never { throw new RuntimeException('private host other.example token secret'); });
        $this->assertSame('REST_FAILED', $result['probes'][0]['reason']);
        $this->assertStringNotContainsString('secret', json_encode($result));
        $this->assertCount(1, $calls);
    }
}
