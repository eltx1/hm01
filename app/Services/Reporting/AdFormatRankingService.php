<?php

namespace App\Services\Reporting;

use App\Models\SiteGamAdFormatReport;
use Illuminate\Support\Collection;

/** Staff-only ranking of websites by Ad Exchange eCPM within each ad format. */
final class AdFormatRankingService
{
    /** @return Collection<string, array{label: string, median_ecpm_minor: int, sites: Collection}> keyed by format */
    public function rank(Collection $sites, string $from, string $to): Collection
    {
        $minImpressions = (int) config('reporting.ad_format_ranking.min_impressions', 1000);
        $sitesById = $sites->keyBy('id');
        $rows = $sitesById->isEmpty() ? collect() : SiteGamAdFormatReport::withoutGlobalScopes()
            ->whereIn('site_id', $sitesById->keys()->all())
            ->whereDate('report_date', '>=', $from)->whereDate('report_date', '<=', $to)
            ->selectRaw('site_id, ad_format, MAX(google_format_name) as google_format_name, SUM(impressions) as impressions, SUM(revenue_micros) as revenue_micros')
            ->groupBy('site_id', 'ad_format')->get();

        return $rows->groupBy('ad_format')->map(function (Collection $formatRows, string $format) use ($sitesById, $minImpressions) {
            $ranked = $formatRows->filter(fn ($row) => (int) $row->impressions >= $minImpressions)
                ->map(fn ($row) => [
                    'site_id' => $row->site_id, 'site' => $sitesById[$row->site_id]?->display_name,
                    'domain' => $sitesById[$row->site_id]?->primary_domain,
                    'publisher' => $sitesById[$row->site_id]?->publisher?->display_name,
                    'publisher_id' => $sitesById[$row->site_id]?->publisher_id,
                    'impressions' => (int) $row->impressions,
                    'revenue_minor' => (int) round($row->revenue_micros / 10000),
                    'ecpm_minor' => (int) round($row->revenue_micros * 1000 / $row->impressions / 10000),
                ])->sortByDesc('ecpm_minor')->values();
            $ecpms = $ranked->pluck('ecpm_minor')->sort()->values();
            $median = $ecpms->isEmpty() ? 0 : ($ecpms->count() % 2 ? $ecpms[intdiv($ecpms->count(), 2)]
                : (int) round(($ecpms[$ecpms->count() / 2 - 1] + $ecpms[$ecpms->count() / 2]) / 2));

            return ['label' => AdFormatCatalog::label($format, $formatRows->first()->google_format_name), 'median_ecpm_minor' => $median, 'sites' => $ranked];
        })->filter(fn ($format) => $format['sites']->isNotEmpty())
            ->sortByDesc(fn ($format) => $format['sites']->sum('revenue_minor'));
    }
}
