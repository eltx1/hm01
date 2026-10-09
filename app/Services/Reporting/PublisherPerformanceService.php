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
        $rows = ReportDisplayQuery::constrain(DailyReport::withoutGlobalScopes(), true)
            ->where('organization_id', $publisher->organization_id)
            ->whereHas('dimension', fn ($query) => $query->where('publisher_id', $publisher->id)
                ->where('organization_id', $publisher->organization_id))
            ->where('currency', $currency)
            ->whereDate('report_date', '>=', $from)
            ->whereDate('report_date', '<=', $to)
            ->with(['dimension.site', 'connection.source'])
            ->get();

        $video = app(VideoPerformanceService::class)->summary($rows, true, $currency, app(VideoPerformanceService::class)->configuration($publisher));
        $rows = $rows->reject(fn ($row) => VideoPerformanceService::isVideo($row));

        return [
            'video' => $video,
            'coverage' => app(ReportCoverageService::class)->forPeriod($from, $to, $currency, $publisher),
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
        $earnings = (int) $rows->sum('publisher_earnings_minor');

        return [
            ...app(PerformanceMetrics::class)->summarize($rows, 'publisher_earnings_minor'),
            'earnings_minor' => $earnings,
            'has_estimates' => $rows->contains('finality', ReportFinality::Estimated),
            'estimated_minor' => (int) $rows->where('finality', ReportFinality::Estimated)->sum('publisher_earnings_minor'),
            'finalized_minor' => (int) $rows->where('finality', ReportFinality::Finalized)->sum('publisher_earnings_minor'),
        ];
    }
}
