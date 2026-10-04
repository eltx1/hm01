<?php

namespace App\Services\Reporting;

use Illuminate\Support\Collection;

final class PerformanceMetrics
{
    public const GOOGLE_COLUMNS = [
        'TOTAL_ACTIVE_VIEW_VIEWABLE_IMPRESSIONS' => 'active_view_viewable_impressions',
        'TOTAL_ACTIVE_VIEW_MEASURABLE_IMPRESSIONS' => 'active_view_measurable_impressions',
        'TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS' => 'unfilled_impressions',
    ];

    public const COLUMNS = [
        'impressions' => 'Impressions',
        'clicks' => 'Clicks',
        'ctr_bp' => 'CTR',
        'ecpm_minor' => 'CPM (eCPM)',
        'viewability_bp' => 'Active View',
        'ad_exchange_unmatched_requests' => 'Ad Exchange unmatched requests',
        'unfilled_impressions' => 'Unfilled impressions',
    ];

    public const COUNTERS = [
        'active_view_viewable_impressions', 'active_view_measurable_impressions', 'unfilled_impressions',
    ];

    /** Never estimate a missing counter, or average percentages across days/sites. */
    public function counters(Collection $rows): array
    {
        $totals = [];
        foreach (self::COUNTERS as $field) {
            $complete = $rows->isNotEmpty() && $rows->every(fn ($row) => data_get($row, $field) !== null);
            $totals[$field] = $complete ? (int) $rows->sum($field) : null;
        }
        $totals['viewability_bp'] = $this->viewability($totals);

        return $totals;
    }

    public function viewability(array $metrics): ?int
    {
        $viewable = $metrics['active_view_viewable_impressions'] ?? null;
        $measurable = $metrics['active_view_measurable_impressions'] ?? null;

        return $viewable !== null && $measurable > 0
            ? (int) round($viewable * 10000 / $measurable) : null;
    }

    /** Callers select gross revenue for staff or already-allocated publisher earnings. */
    public function summarize(Collection $rows, string $revenueField): array
    {
        $basis = app(ReportMetricBasis::class);
        $incomplete = $basis->incomplete($rows);
        $impressions = $basis->counter($rows, 'impressions');
        $clicks = $basis->counter($rows, 'clicks');

        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'metric_basis_incomplete' => $incomplete,
            'has_site_ad_exchange' => $rows->contains(fn ($row) => $basis->siteGam($row)),
            'has_other_sources' => $rows->contains(fn ($row) => $basis->otherSource($row)),
            'ad_exchange_unmatched_requests' => $basis->adExchangeUnmatchedRequests($rows),
            'ctr_bp' => $impressions > 0 && $clicks !== null ? (int) round($clicks * 10000 / $impressions) : null,
            'ecpm_minor' => $impressions > 0 ? (int) round($rows->sum($revenueField) * 1000 / $impressions) : null,
            ...($incomplete ? array_fill_keys([...self::COUNTERS, 'viewability_bp'], null) : $this->counters($rows)),
            // Original SOAP Unfilled has an independent, unit-only basis.
            // Its availability does not depend on exact-hostname AdX counters.
            'unfilled_impressions' => app(SiteGamUnfilledProjection::class)->total($rows),
            'unfilled_scope' => $rows->contains(fn ($row) => $basis->siteGam($row)) ? 'AD_UNIT_ALL_SITES_V1' : null,
        ];
    }

    /** Unfilled impressions keep their source meaning even when unavailable. */
    public static function defaultColumns(array $totals = []): array
    {
        // Preserve existing source-aware metrics and always include true unfilled
        // impressions. The request metric must never replace the source counter.
        if ($totals['has_site_ad_exchange'] ?? false) {
            return array_keys(self::COLUMNS);
        }

        return array_values(array_diff(array_keys(self::COLUMNS), ['ad_exchange_unmatched_requests']));
    }

    public static function display(string $metric, mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($metric) {
            'ctr_bp', 'viewability_bp' => number_format($value / 100, 2).'%',
            'ecpm_minor' => \App\Support\Money::formatMinor((int) $value),
            default => number_format((int) $value),
        };
    }

    public static function label(string $metric, array $context = []): string
    {
        return self::COLUMNS[$metric].($metric === 'unfilled_impressions' && ($context['has_site_ad_exchange'] ?? false)
            ? ' (ad unit, all sites)' : '');
    }
}
