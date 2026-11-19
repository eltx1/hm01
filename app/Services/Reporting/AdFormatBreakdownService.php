<?php

namespace App\Services\Reporting;

use App\Enums\ReportFinality;
use App\Models\DailyReport;
use App\Models\Publisher;
use App\Models\SiteGamAdFormatReport;
use Illuminate\Support\Collection;

/**
 * Read-only "Breakdown by ad format" projection.
 *
 * Money always comes from the existing ledger (daily_reports): for each
 * website/source/day the stored gross revenue, publisher earnings and Horus
 * margin are allocated across Google's inventory formats in proportion to the
 * Ad Exchange revenue Google reported for each format that day. The format
 * totals therefore always add up exactly to the main report. Ledger amounts
 * without format evidence (other demand sources, days not yet synchronized)
 * stay visible as "Not broken down" instead of being guessed.
 */
final class AdFormatBreakdownService
{
    private const COUNTERS = ['impressions', 'clicks', 'ad_requests', 'matched_requests',
        'active_view_viewable_impressions', 'active_view_measurable_impressions'];

    private const MONEY = ['gross_revenue_minor', 'publisher_earnings_minor', 'horus_earnings_minor'];

    /**
     * @param  Collection<int, \App\Models\Site>  $sites  already authorized and organization-scoped
     */
    public function summary(Collection $sites, string $from, string $to, bool $publisherView, ?Publisher $publisher = null, string $dimension = 'FORMAT'): array
    {
        $currency = strtoupper((string) config('reporting.canonical_currency', 'USD'));
        // Site id => organization id: every row must belong to an authorized site of the same tenant.
        $owners = $sites->mapWithKeys(fn ($site) => [$site->id => $site->organization_id])->all();
        $siteIds = array_keys($owners);
        // "All websites" of a publisher uses the main report's own scope (publisher
        // dimension), so publisher-level rows without a website are not lost.
        $ledger = $siteIds === [] && ! $publisher ? collect() : ReportDisplayQuery::constrain(DailyReport::withoutGlobalScopes(), true)
            ->where('currency', $currency)
            ->whereDate('report_date', '>=', $from)->whereDate('report_date', '<=', $to)
            ->when($publisher, fn ($query) => $query->where('organization_id', $publisher->organization_id)
                ->whereHas('dimension', fn ($dimension) => $dimension->where('publisher_id', $publisher->id)
                    ->where('organization_id', $publisher->organization_id)->whereNull('mobile_app_id')))
            ->when(! $publisher, fn ($query) => $query->whereIn('organization_id', array_values(array_unique($owners)))
                ->whereHas('dimension', fn ($dimension) => $dimension->whereIn('site_id', $siteIds)->whereNull('mobile_app_id')))
            ->with(['dimension', 'connection.source'])
            ->get()
            ->reject(fn ($row) => VideoPerformanceService::isVideo($row))
            ->filter(fn ($row) => $row->dimension?->site_id === null
                ? $publisher !== null
                : ($owners[$row->dimension->site_id] ?? null) === $row->organization_id || ($publisher && ! isset($owners[$row->dimension->site_id])));

        $connections = $ledger->pluck('report_source_connection_id')->unique()->values()->all();
        $evidence = $dimension === 'FORMAT' ? SiteGamAdFormatReport::withoutGlobalScopes()
            : \App\Models\SiteGamDimensionReport::withoutGlobalScopes()->where('kind', $dimension);
        $formats = $connections === [] ? collect() : $evidence
            ->whereIn('report_source_connection_id', $connections)
            ->whereDate('report_date', '>=', $from)->whereDate('report_date', '<=', $to)
            ->get()
            ->filter(fn ($row) => isset($owners[$row->site_id]) ? $owners[$row->site_id] === $row->organization_id : $publisher?->organization_id === $row->organization_id)
            ->groupBy(fn ($row) => $row->report_source_connection_id.'|'.$row->report_date->toDateString().'|'.$row->site_id);

        $buckets = [];
        $days = [];
        $lastSynced = null;
        foreach ($ledger->groupBy(fn ($row) => $row->report_source_connection_id.'|'.$row->report_date->toDateString().'|'.$row->dimension?->site_id) as $group) {
            $first = $group->first();
            $date = $first->report_date->toDateString();
            $estimated = $group->contains('finality', ReportFinality::Estimated);
            $money = array_combine(self::MONEY, array_map(fn ($field) => (int) $group->sum($field), self::MONEY));
            $evidence = $first->connection?->connection_type === 'SITE_GAM_AD_UNIT'
                ? ($formats->get($first->report_source_connection_id.'|'.$date.'|'.$first->dimension?->site_id) ?? collect())->values()
                : collect();
            $weights = $evidence->map(fn ($row) => (int) $row->revenue_micros)->all();
            $usable = $evidence->isNotEmpty() && min($weights) >= 0 && (array_sum($weights) > 0 || array_sum($money) === 0);
            if (! $usable) {
                $this->add($buckets, $days, AdFormatCatalog::NOT_BROKEN_DOWN, null, $date, $money,
                    $this->ledgerCounters($group), $estimated, $first->connection?->connection_type === 'SITE_GAM_AD_UNIT');
                continue;
            }
            $allocated = array_combine(self::MONEY, array_map(fn ($field) => $this->allocate($money[$field], $weights), self::MONEY));
            foreach ($evidence as $index => $row) {
                $lastSynced = max($lastSynced, $row->reported_at?->toIso8601String());
                $this->add($buckets, $days, $row->breakdown_key, $row->breakdown_name, $date,
                    array_combine(self::MONEY, array_map(fn ($field) => $allocated[$field][$index], self::MONEY)),
                    array_combine(self::COUNTERS, array_map(fn ($field) => $row->{$field}, self::COUNTERS)), $estimated, false);
            }
        }

        $this->dimension = $dimension;
        if ($dimension === 'COUNTRY') {
            [$buckets, $days] = $this->foldTail($buckets, $days, $publisherView ? 'publisher_earnings_minor' : 'gross_revenue_minor', 15);
        }
        $revenueKey = $publisherView ? 'earnings_minor' : 'gross_revenue_minor';
        $rows = collect($buckets)->map(fn ($bucket) => $this->present($bucket, $publisherView));
        $total = (int) $rows->sum($revenueKey);
        $rows = $rows->map(fn ($row) => $row + ['share_bp' => $total > 0 ? (int) round(max(0, $row[$revenueKey]) * 10000 / $total) : null])
            // Real values by revenue; the folded tail, then amounts without evidence, last.
            ->sortBy(fn ($row) => [match ($row['key']) { AdFormatCatalog::NOT_BROKEN_DOWN => 2, self::OTHER_COUNTRIES => 1, default => 0 }, -$row[$revenueKey], $row['label']])
            ->values();
        $formatKeys = $rows->pluck('key')->all();
        $daily = collect($days)->sortKeysDesc()->map(fn ($cells, $date) => [
            'date' => $date,
            'cells' => collect($formatKeys)->mapWithKeys(fn ($key) => [$key => isset($cells[$key])
                ? (int) $cells[$key][$publisherView ? 'publisher_earnings_minor' : 'gross_revenue_minor'] : null])->all(),
            'total' => (int) collect($cells)->sum($publisherView ? 'publisher_earnings_minor' : 'gross_revenue_minor'),
        ])->values();
        $totals = $this->present(['key' => 'TOTAL', 'name' => null] + $this->merge(array_values($buckets)), $publisherView);

        return [
            'dimension' => $dimension,
            'dimension_label' => ['FORMAT' => 'ad format', 'COUNTRY' => 'country', 'DEVICE' => 'device'][$dimension] ?? 'ad format',
            'from' => $from, 'to' => $to, 'currency' => $currency,
            'available' => $ledger->isNotEmpty(),
            'has_breakdown' => $rows->contains(fn ($row) => $row['key'] !== AdFormatCatalog::NOT_BROKEN_DOWN),
            'has_estimates' => $ledger->contains('finality', ReportFinality::Estimated),
            'revenue_key' => $revenueKey,
            'revenue_label' => $publisherView ? 'Your earnings' : 'Gross revenue',
            'formats' => $rows,
            'not_broken_down' => $rows->firstWhere('key', AdFormatCatalog::NOT_BROKEN_DOWN),
            'totals' => $totals,
            'days' => $daily,
            'format_columns' => $rows->map(fn ($row) => ['key' => $row['key'], 'label' => $row['label']])->all(),
            'synced_at' => $lastSynced,
            'updated_at' => $ledger->max('updated_at'),
        ];
    }

    private string $dimension = 'FORMAT';

    public const OTHER_COUNTRIES = 'OTHER_COUNTRIES';

    private function label(string $key, ?string $name): string
    {
        if ($this->dimension === 'FORMAT' || $key === AdFormatCatalog::NOT_BROKEN_DOWN) return AdFormatCatalog::label($key, $name);
        if ($key === self::OTHER_COUNTRIES) return 'Other countries';
        if ($key === 'UNKNOWN') return 'Unknown';

        return $name !== null && trim($name) !== '' ? trim($name) : ucfirst(strtolower(str_replace('_', ' ', $key)));
    }

    /** Keep the largest values; merge the long tail (e.g. countries) into one row. */
    private function foldTail(array $buckets, array $days, string $field, int $keep): array
    {
        $ranked = collect($buckets)->reject(fn ($bucket) => $bucket['key'] === AdFormatCatalog::NOT_BROKEN_DOWN)->sortByDesc($field)->keys();
        if ($ranked->count() <= $keep + 1) return [$buckets, $days];
        $tail = $ranked->slice($keep)->all();
        $other = ['key' => self::OTHER_COUNTRIES, 'name' => null] + $this->merge(array_values(array_intersect_key($buckets, array_flip($tail))));
        $other['facts'] = 0;
        foreach ($tail as $key) unset($buckets[$key]);
        $buckets[self::OTHER_COUNTRIES] = $other;
        foreach ($days as $date => $cells) {
            foreach ($tail as $key) {
                if (! isset($cells[$key])) continue;
                foreach ($cells[$key] as $moneyField => $value) {
                    $days[$date][self::OTHER_COUNTRIES][$moneyField] = ($days[$date][self::OTHER_COUNTRIES][$moneyField] ?? 0) + $value;
                }
                unset($days[$date][$key]);
            }
        }

        return [$buckets, $days];
    }

    /** Largest-remainder allocation: integer parts that always sum to the ledger amount. */
    public function allocate(int $amount, array $weights): array
    {
        $weights = array_values($weights);
        $sum = array_sum($weights);
        if ($weights === []) return [];
        if ($amount === 0 || $sum <= 0) return array_fill(0, count($weights), 0);
        $sign = $amount < 0 ? -1 : 1;
        $absolute = abs($amount);
        $parts = [];
        $remainders = [];
        foreach ($weights as $index => $weight) {
            $exact = $absolute * ($weight / $sum);
            $parts[$index] = (int) floor($exact);
            $remainders[$index] = $exact - $parts[$index];
        }
        $left = $absolute - array_sum($parts);
        // Stable tie-break by position keeps repeated renders identical.
        uksort($remainders, fn ($a, $b) => [$remainders[$b], $a] <=> [$remainders[$a], $b]);
        foreach (array_keys($remainders) as $index) {
            if ($left <= 0) break;
            $parts[$index]++;
            $left--;
        }
        // Floating point can only leave a tiny residue; settle it on the largest share.
        if ($left !== 0) $parts[array_keys($weights, max($weights))[0]] += $left;
        ksort($parts);

        return array_map(fn ($part) => $sign * $part, $parts);
    }

    private function ledgerCounters(Collection $group): array
    {
        $basis = app(ReportMetricBasis::class);
        $counters = [
            'impressions' => $basis->counter($group, 'impressions'),
            'clicks' => $basis->counter($group, 'clicks'),
        ];
        foreach (['ad_requests', 'matched_requests', 'active_view_viewable_impressions', 'active_view_measurable_impressions'] as $field) {
            $counters[$field] = $basis->incomplete($group) || $group->contains(fn ($row) => $row->{$field} === null || ! $basis->siteGam($row))
                ? null : (int) $group->sum($field);
        }

        return $counters;
    }

    private function add(array &$buckets, array &$days, string $key, ?string $name, string $date, array $money, array $counters, bool $estimated, bool $pending): void
    {
        $bucket = $buckets[$key] ?? ['key' => $key, 'name' => $name, 'has_estimates' => false, 'pending' => false, 'facts' => 0,
            ...array_fill_keys(self::MONEY, 0), ...array_fill_keys(self::COUNTERS, 0)];
        foreach (self::MONEY as $field) $bucket[$field] += $money[$field];
        foreach (self::COUNTERS as $field) {
            $bucket[$field] = $bucket[$field] === null || $counters[$field] === null ? null : $bucket[$field] + $counters[$field];
        }
        $bucket['name'] ??= $name;
        $bucket['has_estimates'] = $bucket['has_estimates'] || $estimated;
        $bucket['pending'] = $bucket['pending'] || $pending;
        $bucket['facts']++;
        $buckets[$key] = $bucket;
        foreach (self::MONEY as $field) {
            $days[$date][$key][$field] = ($days[$date][$key][$field] ?? 0) + $money[$field];
        }
    }

    private function merge(array $buckets): array
    {
        $merged = ['has_estimates' => false, 'pending' => false, ...array_fill_keys(self::MONEY, 0), ...array_fill_keys(self::COUNTERS, 0)];
        foreach ($buckets as $bucket) {
            foreach (self::MONEY as $field) $merged[$field] += $bucket[$field];
            foreach (self::COUNTERS as $field) {
                $merged[$field] = $merged[$field] === null || $bucket[$field] === null ? null : $merged[$field] + $bucket[$field];
            }
            $merged['has_estimates'] = $merged['has_estimates'] || $bucket['has_estimates'];
        }
        if ($buckets === []) foreach (self::COUNTERS as $field) $merged[$field] = null;

        return $merged;
    }

    /** Publisher rows never contain gross revenue or Horus margin. */
    private function present(array $bucket, bool $publisherView): array
    {
        $revenue = $publisherView ? $bucket['publisher_earnings_minor'] : $bucket['gross_revenue_minor'];
        $impressions = $bucket['impressions'];
        $clicks = $bucket['clicks'];
        $requests = $bucket['ad_requests'];
        $matched = $bucket['matched_requests'];
        $row = [
            'key' => $bucket['key'],
            'label' => $bucket['key'] === 'TOTAL' ? 'Total' : $this->label($bucket['key'], $bucket['name'] ?? null),
            'has_estimates' => $bucket['has_estimates'],
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr_bp' => $impressions > 0 && $clicks !== null ? (int) round($clicks * 10000 / $impressions) : null,
            'ecpm_minor' => $impressions > 0 ? (int) round($revenue * 1000 / $impressions) : null,
            'viewability_bp' => app(PerformanceMetrics::class)->viewability($bucket),
            'ad_requests' => $requests,
            'ad_exchange_unmatched_requests' => $requests !== null && $matched !== null && $requests >= $matched ? $requests - $matched : null,
            // Google reports Unfilled per ad unit only, never per inventory format.
            'unfilled_impressions' => null,
            'has_site_ad_exchange' => true,
        ];

        return $publisherView
            ? $row + ['earnings_minor' => $bucket['publisher_earnings_minor']]
            : $row + ['gross_revenue_minor' => $bucket['gross_revenue_minor'], 'publisher_earnings_minor' => $bucket['publisher_earnings_minor'],
                'horus_earnings_minor' => $bucket['horus_earnings_minor']];
    }
}
