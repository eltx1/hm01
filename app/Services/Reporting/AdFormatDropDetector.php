<?php

namespace App\Services\Reporting;

use App\Models\SiteGamAdFormatReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Finds sharp drops for one completed day versus each website-format's own
 * recent baseline. Read-only: uses the stored Google format evidence only.
 * A day that has not been synchronized for a website is unknown, never a drop.
 */
final class AdFormatDropDetector
{
    public const BASELINE_DAYS = 14;

    /** @return Collection<int, array> drops, most severe first */
    public function detect(string $day, ?array $siteIds = null): Collection
    {
        $config = config('reporting.ad_format_alerts', []);
        $minBaselineDays = (int) ($config['min_baseline_days'] ?? 7);
        $minRevenueMicros = (int) ($config['min_baseline_revenue_micros'] ?? 5_000_000);
        $revenueRatio = (float) ($config['revenue_drop_ratio'] ?? 0.5);
        $minImpressions = (int) ($config['min_baseline_impressions'] ?? 1000);
        $ecpmRatio = (float) ($config['ecpm_drop_ratio'] ?? 0.6);
        $from = CarbonImmutable::parse($day)->subDays(self::BASELINE_DAYS)->toDateString();

        $rows = SiteGamAdFormatReport::withoutGlobalScopes()->with(['site' => fn ($q) => $q->withoutGlobalScopes()->with(['publisher' => fn ($p) => $p->withoutGlobalScopes()])])
            ->whereDate('report_date', '>=', $from)->whereDate('report_date', '<=', $day)
            ->when($siteIds !== null, fn ($q) => $q->whereIn('site_id', $siteIds ?: ['']))
            ->get();
        $drops = collect();
        foreach ($rows->groupBy('site_id') as $siteRows) {
            $observedDays = $siteRows->map(fn ($row) => $row->report_date->toDateString())->unique();
            // The day must have been synchronized for this website.
            if (! $observedDays->contains($day)) continue;
            foreach ($siteRows->groupBy('ad_format') as $format => $formatRows) {
                $baseline = $formatRows->filter(fn ($row) => $row->report_date->toDateString() < $day);
                $baselineDays = $observedDays->filter(fn ($date) => $date < $day)->count();
                if ($baselineDays < $minBaselineDays || $baseline->isEmpty()) continue;
                $current = $formatRows->first(fn ($row) => $row->report_date->toDateString() === $day);
                $avgRevenue = (int) round($baseline->sum('revenue_micros') / $baselineDays);
                $avgImpressions = (int) round($baseline->sum('impressions') / $baselineDays);
                $revenue = (int) ($current?->revenue_micros ?? 0);
                $impressions = (int) ($current?->impressions ?? 0);
                $site = $formatRows->first()->site;
                $base = ['site_id' => $site?->id, 'site' => $site?->display_name, 'domain' => $site?->primary_domain,
                    'publisher_id' => $site?->publisher_id, 'publisher' => $site?->publisher?->display_name,
                    'format' => $format, 'format_label' => AdFormatCatalog::label($format, $formatRows->first()->google_format_name), 'day' => $day];
                if ($avgRevenue >= $minRevenueMicros && $revenue < $avgRevenue * $revenueRatio) {
                    $drops->push($base + ['metric' => 'revenue', 'current' => $this->minor($revenue), 'baseline' => $this->minor($avgRevenue),
                        'change_bp' => (int) round(($revenue - $avgRevenue) * 10000 / $avgRevenue)]);
                    continue;
                }
                $baselineEcpm = $baseline->sum('impressions') > 0 ? $baseline->sum('revenue_micros') * 1000 / $baseline->sum('impressions') : 0;
                $ecpm = $impressions > 0 ? $revenue * 1000 / $impressions : 0;
                if ($avgImpressions >= $minImpressions && $impressions >= $avgImpressions / 2 && $baselineEcpm > 0 && $ecpm < $baselineEcpm * $ecpmRatio) {
                    $drops->push($base + ['metric' => 'ecpm', 'current' => $this->minor((int) round($ecpm)), 'baseline' => $this->minor((int) round($baselineEcpm)),
                        'change_bp' => (int) round(($ecpm - $baselineEcpm) * 10000 / $baselineEcpm)]);
                }
            }
        }

        return $drops->sortBy('change_bp')->values();
    }

    private function minor(int $micros): int
    {
        return ($micros < 0 ? -1 : 1) * intdiv(abs($micros) + 5000, 10000);
    }
}
