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
        $rows = $this->rows([$site->id], $from, $to, daily: true);

        return [
            'from' => $from, 'to' => $to, 'currency' => $this->currency(),
            'available' => $rows->isNotEmpty(), 'updated_at' => $rows->max('updated_at'),
            ...$this->totals($rows),
            'days' => $rows->groupBy(fn ($row) => $row->report_date->toDateString())->sortKeys()
                ->map(fn ($group, $date) => ['date' => $date, ...$this->totals($group)])->values(),
        ];
    }

    public function currency(): string
    {
        return strtoupper((string) config('reporting.canonical_currency', 'USD'));
    }

    private function rows(array $siteIds, string $from, string $to, bool $daily = false): Collection
    {
        // Aggregate before hydration: one row per site (directory) or day (detail).
        // Keep the DailyReport organization scope and canonical/finalized basis.
        $query = DailyReport::query()
            ->join('report_dimensions', 'report_dimensions.id', '=', 'daily_reports.report_dimension_id')
            ->where('daily_reports.finality', ReportFinality::Finalized->value)
            ->where('daily_reports.currency', $this->currency())
            ->whereBetween('daily_reports.report_date', [$from, $to])
            ->whereIn('report_dimensions.site_id', $siteIds)
            ->select('report_dimensions.site_id')
            ->selectRaw('MAX(daily_reports.updated_at) as updated_at')
            ->groupBy('report_dimensions.site_id');
        if ($daily) {
            $query->addSelect('daily_reports.report_date')->groupBy('daily_reports.report_date');
        }
        foreach (['impressions', 'clicks', 'gross_revenue_minor', 'publisher_earnings_minor', 'horus_earnings_minor'] as $field) {
            $query->selectRaw("SUM(daily_reports.{$field}) as {$field}");
        }
        foreach (PerformanceMetrics::COUNTERS as $field) {
            // A partial counter must stay unavailable, not silently sum known rows.
            $query->selectRaw("CASE WHEN COUNT(daily_reports.{$field}) = COUNT(*) THEN SUM(daily_reports.{$field}) ELSE NULL END as {$field}");
        }

        return $query->get();
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
