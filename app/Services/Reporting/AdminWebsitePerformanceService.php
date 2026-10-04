<?php

namespace App\Services\Reporting;

use App\Enums\ReportFinality;
use App\Models\DailyReport;
use App\Models\Site;
use Illuminate\Support\Collection;

final class AdminWebsitePerformanceService
{
    public function summaries(Collection $sites, string $from, string $to): Collection
    {
        return $this->rows($sites->pluck('id')->all(), $from, $to)
            ->groupBy('site_id')
            ->map(fn ($rows) => $this->totals($rows));
    }

    public function summary(Site $site, string $from, string $to): array
    {
        $rows = $this->rows([$site->id], $from, $to);

        return [
            'video' => $this->video($site, $from, $to),
            'from' => $from, 'to' => $to, 'currency' => $this->currency(),
            'available' => $rows->isNotEmpty(), 'updated_at' => $rows->max('updated_at'),
            ...$this->totals($rows),
            'days' => $rows->groupBy(fn ($row) => $row->report_date->toDateString())->sortKeys()
                ->map(fn ($group, $date) => ['date' => $date, ...$this->totals($group)])->values(),
        ];
    }

    private function video(Site $site, string $from, string $to): array
    {
        $rows = VideoPerformanceService::constrain(DailyReport::query(), true)
            ->where('organization_id', $site->organization_id)
            ->whereHas('dimension', fn ($query) => $query->where('site_id', $site->id)->where('organization_id', $site->organization_id))
            ->where('currency', $this->currency())->where('finality', ReportFinality::Finalized->value)
            ->whereDate('report_date', '>=', $from)->whereDate('report_date', '<=', $to)
            ->with(['dimension.site', 'connection.source'])->get();

        return app(VideoPerformanceService::class)->summary($rows, false, $this->currency(), app(VideoPerformanceService::class)->configuration($site));
    }

    public function currency(): string
    {
        return strtoupper((string) config('reporting.canonical_currency', 'USD'));
    }

    private function rows(array $siteIds, string $from, string $to): Collection
    {
        // Keep source/day/unit identity until the independent unit totals have
        // been deduplicated. Financial amounts still aggregate each fact once.
        $query = DailyReport::query()
            ->join('report_dimensions', 'report_dimensions.id', '=', 'daily_reports.report_dimension_id')
            ->where('daily_reports.finality', ReportFinality::Finalized->value)
            ->where('daily_reports.currency', $this->currency())
            ->whereDate('daily_reports.report_date', '>=', $from)
            ->whereDate('daily_reports.report_date', '<=', $to)
            ->whereIn('report_dimensions.site_id', $siteIds)
            ->select('report_dimensions.site_id', 'daily_reports.organization_id',
                'daily_reports.report_source_connection_id', 'daily_reports.report_date')
            ->addSelect('report_dimensions.site_id as metric_unit_site_id',
                'report_dimensions.organization_id as metric_unit_organization_id',
                'report_dimensions.gam_connection_id as metric_unit_gam_connection_id')
            ->selectRaw('MAX(daily_reports.updated_at) as updated_at')
            ->groupBy('report_dimensions.site_id', 'daily_reports.organization_id',
                'daily_reports.report_source_connection_id', 'daily_reports.report_date',
                'report_dimensions.gam_connection_id', 'report_dimensions.organization_id');
        VideoPerformanceService::constrain($query, false);
        $grammar = $query->getQuery()->getGrammar();
        $unit = $grammar->wrap('report_dimensions.external_dimensions->gam_ad_unit_id');
        $legacy = implode(' AND ', array_map(fn ($field) => $grammar->wrap('report_dimensions.external_dimensions->'.$field).' IS NULL',
            ['gam_report_basis', 'gam_report_scope', 'gam_report_site']));
        $query->selectRaw("{$unit} as metric_unit_ad_unit_id")
            ->groupByRaw($unit)
            ->selectRaw("CASE WHEN SUM(CASE WHEN {$legacy} THEN 0 ELSE 1 END) = 0 THEN 1 ELSE 0 END as metric_unit_legacy")
            ->selectRaw('CASE WHEN COUNT(daily_reports.unfilled_impressions) = COUNT(*) AND MIN(daily_reports.unfilled_impressions) = MAX(daily_reports.unfilled_impressions) THEN MAX(daily_reports.unfilled_impressions) ELSE NULL END as metric_legacy_unfilled_impressions');
        foreach (['gross_revenue_minor', 'publisher_earnings_minor', 'horus_earnings_minor'] as $field) {
            $query->selectRaw("SUM(daily_reports.{$field}) as {$field}");
        }
        app(ReportMetricBasis::class)->selectCounters($query, ['impressions', 'clicks', ...PerformanceMetrics::COUNTERS]);

        $rows = $query->get();
        app(SiteGamUnfilledProjection::class)->preload($rows);

        return $rows;
    }

    private function totals(Collection $rows): array
    {
        return [
            ...app(PerformanceMetrics::class)->summarize($rows, 'gross_revenue_minor'),
            'gross_revenue_minor' => (int) $rows->sum('gross_revenue_minor'),
            'publisher_earnings_minor' => (int) $rows->sum('publisher_earnings_minor'),
            'horus_earnings_minor' => (int) $rows->sum('horus_earnings_minor'),
        ];
    }
}
