<?php

function horusFinancePreviewIdentity(\App\Models\SiteGamVideoReportBinding $binding): string
{
    return hash('sha256', implode('|', [$binding->site_id, $binding->id, $binding->gam_connection_id,
        $binding->network_code, $binding->ad_unit_id, $binding->ad_unit_code, $binding->starts_on->toDateString()]));
}

/** Audited Google reads may write API audit records only, never accounting/configuration. */
function horusFinancePreviewSqlAllowed(string $sql, string $prefix = ''): bool
{
    if (preg_match('/^\s*(select|show|explain)\b/i', $sql)) return true;
    if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) return false;
    return (bool) preg_match('/^\s*(?:insert\s+into|update)\s+`?'.preg_quote($prefix, '/').'(?:gam_api_operations|gam_errors)`?\s+(?:\(|set\b)/i', $sql);
}

function horusFinanceVideoPreviewContext(\App\Models\SiteGamVideoReportBinding $binding, string $expectedIdentity, ?array $expectedContext = null): array
{
    $binding->loadMissing(['site', 'connection', 'gamConnection']);
    if (!hash_equals($expectedIdentity, horusFinancePreviewIdentity($binding))
        || $binding->active_site_id !== $binding->site_id || $binding->ends_on !== null
        || !$binding->connection?->is_enabled || !$binding->gamConnection?->is_enabled
        || $binding->connection->currency !== 'USD'
        || $binding->network_code !== (string) $binding->gamConnection->network_code
        || $binding->organization_id !== $binding->site->organization_id) throw new \RuntimeException('Preview source changed');
    $context = ['metric_basis' => \App\Services\Reporting\SiteGamReportMetrics::BASIS, 'currency' => 'USD',
        'from' => '2026-09-30', 'to' => '2026-09-30', 'hostname' => strtolower($binding->site->primary_domain),
        'ad_unit_id' => $binding->ad_unit_id, 'network_code' => $binding->network_code,
        'timezone' => $binding->connection->timezone];
    if ($expectedContext !== null && $context !== $expectedContext) throw new \RuntimeException('Preview scope changed');
    return $context;
}

/** Collect evidence only. No imports, source cutovers, statements or payment operations. */
function horusFinanceVideoPreview(\App\Models\SiteGamVideoReportBinding $binding, string $identity): array
{
    $context = horusFinanceVideoPreviewContext($binding, $identity);
    $originalScope = $context;
    if (!app(\App\Services\Reporting\SiteGamVideoReportingService::class)->availableConnections($binding->site)
        ->whereKey($binding->gam_connection_id)->exists()) throw new \RuntimeException('Preview connection unavailable');
    $google = app(\App\Services\Reporting\GamAdUnitReportClient::class);
    $network = $google->call($binding->gamConnection, 'NetworkService', 'getCurrentNetwork');
    if ((string) ($network['networkCode'] ?? '') !== $context['network_code'] || ($network['timeZone'] ?? '') !== $context['timezone']
        || !preg_match('/^[A-Z]{3}$/D', $network['currencyCode'] ?? '')) throw new \RuntimeException('Preview network changed');
    $context['network_currency'] = $network['currencyCode'];
    $report = app(\App\Services\Reporting\GamHistoricalCorrectionReport::class);
    $query = $report->query($context);
    $response = $google->runPerformanceReport($binding->gamConnection, $query, array_keys(\App\Services\Reporting\SiteGamReportMetrics::OPTIONAL_COLUMNS));
    $jobId = (string) ($response['id'] ?? '');
    if (!ctype_digit($jobId)) throw new \RuntimeException('Invalid preview job');
    $currency = app(\App\Services\Reporting\GamReportMoneyParser::class)->confirmedCurrency($response, 'USD');
    $deadline = hrtime(true) + 75 * 1_000_000_000;
    do {
        $status = $google->call($binding->gamConnection, 'ReportService', 'getReportJobStatus', ['reportJobId' => $jobId])['value'] ?? '';
        if ($status === 'COMPLETED') {
            $csv = $google->download($binding->gamConnection, $jobId);
            $evidence = $report->parse($csv, $context, $currency);
            horusFinanceVideoPreviewContext($binding->fresh(['site', 'connection', 'gamConnection']), $identity, $originalScope);
            return ['scope' => $context, 'query_sha256' => $report->queryHash($context), 'evidence' => $evidence,
                'financial_records_changed' => false];
        }
        if ($status !== 'IN_PROGRESS') throw new \RuntimeException('Preview job failed');
        usleep(5_000_000);
    } while (hrtime(true) < $deadline);
    throw new \RuntimeException('Preview job still pending');
}
