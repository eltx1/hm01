<?php

namespace App\Services\Reporting;

use App\Models\DailyReport;
use App\Models\Site;
use App\Models\SiteGamAdFormatReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Revenue opportunities": web formats that peers earn from but this website
 * does not. The estimate uses the network median share of each format among
 * at least N peer websites (aggregate only, never one peer's figures).
 */
final class AdFormatOpportunityService
{
    public const WINDOW_DAYS = 30;

    public const WEB_FORMATS = ['BANNER', 'INTERSTITIAL', 'ANCHOR', 'REWARDED', 'REWARDED_INTERSTITIAL', 'SIDE_RAIL', 'NATIVE'];

    /** @return Collection<int, array{format: string, label: string, peer_sites: int, median_share_bp: int, estimated_minor: int}> */
    public function forSite(Site $site, bool $publisherView, ?string $today = null): Collection
    {
        $to = CarbonImmutable::parse($today ?? now('UTC')->toDateString())->subDay();
        $from = $to->subDays(self::WINDOW_DAYS - 1);
        $minPeers = max(3, (int) config('reporting.ad_format_opportunities.min_peer_sites', 3));
        $rows = SiteGamAdFormatReport::withoutGlobalScopes()
            ->whereDate('report_date', '>=', $from->toDateString())->whereDate('report_date', '<=', $to->toDateString())
            ->whereIn('ad_format', self::WEB_FORMATS)
            ->selectRaw('site_id, ad_format, SUM(revenue_micros) as revenue_micros')->groupBy('site_id', 'ad_format')->get();
        $bySite = $rows->groupBy('site_id')->map(fn ($formats) => $formats->mapWithKeys(fn ($row) => [$row->ad_format => max(0, (int) $row->revenue_micros)]));
        $mine = $bySite->get($site->id);
        $myTotal = $mine ? $mine->sum() : 0;
        if ($myTotal <= 0) return collect();

        $opportunities = collect();
        foreach (self::WEB_FORMATS as $format) {
            if (($mine[$format] ?? 0) > 0) continue;
            $shares = $bySite->except($site->id)->filter(fn ($formats) => ($formats[$format] ?? 0) > 0 && $formats->sum() > 0)
                ->map(fn ($formats) => $formats[$format] / $formats->sum())->values()->sort()->values();
            if ($shares->count() < $minPeers) continue;
            $median = $shares->count() % 2 ? $shares[intdiv($shares->count(), 2)] : ($shares[$shares->count() / 2 - 1] + $shares[$shares->count() / 2]) / 2;
            if ($median <= 0 || $median >= 0.95) continue;
            // Adding the format on top of current revenue: x / (total + x) = median.
            $estimatedMicros = $myTotal * $median / (1 - $median);
            $opportunities->push(['format' => $format, 'label' => AdFormatCatalog::label($format), 'peer_sites' => $shares->count(),
                'median_share_bp' => (int) round($median * 10000), 'estimated_minor' => $this->toMinor($estimatedMicros)]);
        }
        if ($publisherView && $opportunities->isNotEmpty()) {
            // Publisher-safe: convert with this website's own earnings ratio for the same window.
            $ledger = DailyReport::withoutGlobalScopes()->where('organization_id', $site->organization_id)
                ->whereHas('dimension', fn ($q) => $q->where('site_id', $site->id))
                ->whereDate('report_date', '>=', $from->toDateString())->whereDate('report_date', '<=', $to->toDateString())
                ->selectRaw('SUM(gross_revenue_minor) as gross, SUM(publisher_earnings_minor) as publisher')->first();
            $ratio = (int) ($ledger->gross ?? 0) > 0 ? (int) $ledger->publisher / (int) $ledger->gross : 0;
            $opportunities = $opportunities->map(fn ($item) => ['estimated_minor' => (int) round($item['estimated_minor'] * $ratio)] + $item);
        }

        return $opportunities->filter(fn ($item) => $item['estimated_minor'] > 0)->sortByDesc('estimated_minor')->values();
    }

    private function toMinor(float $micros): int
    {
        return (int) round($micros / 10000);
    }
}
