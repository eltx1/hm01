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
            ->groupBy(fn ($row) => $row->dimension->site_id)
            ->map(fn ($rows) => $this->totals($rows));
    }

    public function summary(Site $site, string $from, string $to): array
    {
        $rows = $this->rows([$site->id], $from, $to);

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

    private function rows(array $siteIds, string $from, string $to): Collection
    {
        // Same finalized, canonical-currency basis as the admin reporting overview.
        return DailyReport::query()->where('finality', ReportFinality::Finalized->value)
            ->where('currency', $this->currency())
            ->whereDate('report_date', '>=', $from)->whereDate('report_date', '<=', $to)
            ->whereHas('dimension', fn ($query) => $query->whereIn('site_id', $siteIds))
            ->with('dimension')->get();
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
