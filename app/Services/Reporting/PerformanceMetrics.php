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
            'ctr_bp' => $impressions > 0 && $clicks !== null ? (int) round($clicks * 10000 / $impressions) : null,
            'ecpm_minor' => $impressions > 0 ? (int) round($rows->sum($revenueField) * 1000 / $impressions) : null,
            ...($incomplete ? array_fill_keys([...self::COUNTERS, 'viewability_bp'], null) : $this->counters($rows)),
        ];
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
}
