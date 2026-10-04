<?php

namespace App\Services\Reporting;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Read-only performance projection. Ledger amounts and settlement inputs are never changed. */
final class ReportMetricBasis
{
    public function incomplete(Collection $rows): bool
    {
        return $rows->contains(fn ($row) => $this->rowIncomplete($row));
    }

    public function rowIncomplete(mixed $row): bool
    {
        // SQL summaries already evaluated every underlying fact before grouping.
        if (data_get($row, 'metric_basis_incomplete') !== null) {
            return (int) data_get($row, 'metric_basis_incomplete') > 0;
        }
        if (! $this->siteGam($row)) return false;

        $external = data_get($row, 'dimension.external_dimensions', []);
        if (data_get($external, 'gam_report_basis') !== SiteGamReportMetrics::BASIS) return true;
        foreach (['gam_report_site', 'gam_ad_unit_id', 'gam_report_scope'] as $field) {
            $value = data_get($external, $field);
            if (! is_string($value) || trim($value) === '' || str_contains($value, "\0")) return true;
        }

        // Historical correction provenance is valid independently of today's binding or hostname.
        return false;
    }

    public function siteGam(mixed $row): bool
    {
        if (data_get($row, 'metric_site_gam_rows') !== null) {
            return (int) data_get($row, 'metric_site_gam_rows') > 0;
        }
        $source = data_get($row, 'connection.source');
        $code = $source instanceof \Illuminate\Database\Eloquent\Model
            ? $source->getRawOriginal('code') : data_get($source, 'code');
        if ($code instanceof BackedEnum) $code = $code->value;
        return in_array(data_get($row, 'connection.connection_type'), ['SITE_GAM_AD_UNIT', 'SITE_GAM_VIDEO_AD_UNIT'], true) || in_array($code, ['GAM_AD_UNIT', 'GAM_VIDEO_AD_UNIT'], true);
    }

    public function otherSource(mixed $row): bool
    {
        return data_get($row, 'metric_other_source_rows') !== null
            ? (int) data_get($row, 'metric_other_source_rows') > 0 : ! $this->siteGam($row);
    }

    /** A separate AdX request metric, never Google's inventory-wide unfilled impressions. */
    public function adExchangeUnmatchedRequests(Collection $rows): ?int
    {
        if ($rows->isEmpty()) return null;
        $total = 0;
        foreach ($rows as $row) {
            if (! $this->siteGam($row) || $this->otherSource($row) || $this->rowIncomplete($row)) return null;
            if (data_get($row, 'metric_site_gam_rows') !== null) {
                // SQL has already validated each fact before aggregating. Never
                // let a valid day cancel out another day's inconsistent counts.
                $value = data_get($row, 'ad_exchange_unmatched_requests');
                if ($value === null) return null;
                $total += (int) $value;
                continue;
            }
            $requests = data_get($row, 'ad_requests');
            $responses = data_get($row, 'matched_requests');
            if ($requests === null || $responses === null || $requests < 0 || $responses < 0 || $responses > $requests) return null;
            $total += (int) $requests - (int) $responses;
        }

        return $total;
    }

    public function counter(Collection $rows, string $field): ?int
    {
        if ($this->incomplete($rows) || $rows->contains(fn ($row) => data_get($row, $field) === null)) return null;

        return (int) $rows->sum($field);
    }

    /** The caller retains its organization, date, finality and currency restrictions. */
    public function selectCounters(Builder $query, array $fields): void
    {
        $query->leftJoin('report_source_connections as metric_connections', 'metric_connections.id', '=', 'daily_reports.report_source_connection_id')
            ->leftJoin('report_sources as metric_sources', 'metric_sources.id', '=', 'metric_connections.report_source_id');
        $grammar = $query->getQuery()->getGrammar();
        $driver = $query->getConnection()->getDriverName();
        $exact = static fn (string $expression): string => $driver === 'sqlite'
            ? "({$expression} COLLATE BINARY)" : "CAST({$expression} AS BINARY)";
        $parts = [];
        foreach (['gam_report_basis', 'gam_report_site', 'gam_ad_unit_id', 'gam_report_scope'] as $field) {
            $value = $grammar->wrap('report_dimensions.external_dimensions->'.$field);
            $type = $driver === 'sqlite'
                ? "json_type(report_dimensions.external_dimensions, '$.{$field}')"
                : "JSON_TYPE(JSON_EXTRACT(report_dimensions.external_dimensions, '$.{$field}'))";
            $stringType = $driver === 'sqlite' ? 'text' : 'STRING';
            $parts[] = "COALESCE({$type}, '') <> '{$stringType}'";
            $parts[] = "INSTR(COALESCE({$value}, ''), CHAR(0)) > 0";
            $nonblank = "COALESCE({$value}, '')";
            foreach ([9, 10, 11, 13] as $character) $nonblank = "REPLACE({$nonblank}, CHAR({$character}), '')";
            $parts[] = $field === 'gam_report_basis'
                ? $exact("COALESCE({$value}, '')")." <> '".SiteGamReportMetrics::BASIS."'"
                : "TRIM({$nonblank}) = ''";
        }
        $siteGam = '('.$exact("COALESCE(metric_connections.connection_type, '')")." IN ('SITE_GAM_AD_UNIT', 'SITE_GAM_VIDEO_AD_UNIT') OR "
            .$exact("COALESCE(metric_sources.code, '')")." IN ('GAM_AD_UNIT', 'GAM_VIDEO_AD_UNIT'))";
        $invalid = '('.$siteGam.' AND ('.implode(' OR ', $parts).'))';
        $missing = "SUM(CASE WHEN {$invalid} THEN 1 ELSE 0 END)";
        $query->selectRaw("{$missing} as metric_basis_incomplete");
        $query->selectRaw("SUM(CASE WHEN {$siteGam} THEN 1 ELSE 0 END) as metric_site_gam_rows");
        $query->selectRaw("SUM(CASE WHEN {$siteGam} THEN 0 ELSE 1 END) as metric_other_source_rows");
        $validRequests = "daily_reports.ad_requests IS NOT NULL AND daily_reports.matched_requests IS NOT NULL AND daily_reports.matched_requests >= 0 AND daily_reports.ad_requests >= daily_reports.matched_requests";
        $query->selectRaw("CASE WHEN SUM(CASE WHEN {$siteGam} AND NOT {$invalid} AND {$validRequests} THEN 0 ELSE 1 END) = 0 "
            ."THEN SUM(CASE WHEN {$validRequests} THEN daily_reports.ad_requests - daily_reports.matched_requests ELSE 0 END) ELSE NULL END as ad_exchange_unmatched_requests");
        foreach ($fields as $field) {
            if (! in_array($field, ['ad_requests', 'matched_requests', 'unfilled_requests', 'impressions', 'clicks', 'video_starts', 'completed_views', ...PerformanceMetrics::COUNTERS], true)) {
                throw new \InvalidArgumentException('Unknown performance counter.');
            }
            $query->selectRaw("CASE WHEN {$missing} = 0 AND COUNT(daily_reports.{$field}) = COUNT(*) THEN SUM(daily_reports.{$field}) ELSE NULL END as {$field}");
        }
    }
}
