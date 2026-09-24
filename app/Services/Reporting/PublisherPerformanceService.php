<?php

namespace App\Services\Reporting;

use App\Enums\ReportFinality;
use App\Models\DailyReport;
use App\Models\Publisher;
use Illuminate\Support\Collection;

final class PublisherPerformanceService
{
    /** Publisher-safe projection: no gross revenue, margin or other tenant data. */
    public function summary(Publisher $publisher, string $from, string $to): array
    {
        $currency = strtoupper((string) config('reporting.canonical_currency', 'USD'));
        $rows = DailyReport::withoutGlobalScopes()
            ->whereHas('dimension', fn ($query) => $query->where('publisher_id', $publisher->id)
                ->where('organization_id', $publisher->organization_id))
            ->where('currency', $currency)
            ->whereBetween('report_date', [$from, $to])
            ->whereIn('finality', [ReportFinality::Estimated->value, ReportFinality::Finalized->value])
            ->with('dimension.site')
            ->get();

        return [
            'from' => $from, 'to' => $to, 'currency' => $currency,
            'available' => $rows->isNotEmpty(),
            'updated_at' => $rows->max('updated_at'),
            ...$this->totals($rows),
            'days' => $rows->groupBy(fn ($row) => $row->report_date->toDateString())->sortKeys()
                ->map(fn ($group, $date) => ['label' => $date, ...$this->totals($group)])->values(),
            'websites' => $rows->groupBy(fn ($row) => $row->dimension?->site_id ?? 'unassigned')
                ->map(fn ($group) => ['label' => $group->first()->dimension?->site?->display_name ?? 'Unassigned', ...$this->totals($group)])
                ->sortByDesc('earnings_minor')->values(),
        ];
    }

    private function totals(Collection $rows): array
    {
        $impressions = (int) $rows->sum('impressions');
        $earnings = (int) $rows->sum('publisher_earnings_minor');

        return [
            'impressions' => $impressions,
            'clicks' => (int) $rows->sum('clicks'),
            'earnings_minor' => $earnings,
            'estimated_minor' => (int) $rows->where('finality', ReportFinality::Estimated)->sum('publisher_earnings_minor'),
            'finalized_minor' => (int) $rows->where('finality', ReportFinality::Finalized)->sum('publisher_earnings_minor'),
            'ecpm_minor' => $impressions > 0 ? (int) round($earnings * 1000 / $impressions) : 0,
        ];
    }
}
