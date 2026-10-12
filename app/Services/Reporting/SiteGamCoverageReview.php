<?php

namespace App\Services\Reporting;

use App\Models\BidderAccount;
use App\Models\ConfigVersion;
use App\Models\DemandAccount;
use App\Models\FinancialPeriod;
use App\Models\Site;

/** Server-owned evidence for ongoing declarations; never an automatic revenue attestation. */
final class SiteGamCoverageReview
{
    public function capture(DemandAccount|BidderAccount $subject): array
    {
        $sites = [];
        foreach ($this->sites($subject) as $site) {
            $version = $this->versions($site)->orderByDesc('published_at')->orderByDesc('id')->first();
            $sites[$site->id] = $this->fingerprint($subject, $site, (array) $version?->payload);
        }
        return ['reviewed_at' => now()->toIso8601String(), 'sites' => $sites];
    }

    public function changed(DemandAccount|BidderAccount $subject, FinancialPeriod $period): bool
    {
        $review = data_get($subject->financialBinding?->configuration, 'site_gam_coverage_review');
        // Existing explicit declarations retain their semantics until re-reviewed.
        if (! is_array($review)) return false;
        foreach ($this->sites($subject) as $site) {
            if ($site->created_at->toDateString() > $period->ends_on->toDateString()) continue;
            if (! isset($review['sites'][$site->id])) return true;
            foreach ($this->versions($site)->where('published_at', '>=', \Carbon\CarbonImmutable::parse($review['reviewed_at']))
                ->where('published_at', '<=', $period->ends_on->copy()->endOfDay())->cursor() as $version) {
                if (! hash_equals($review['sites'][$site->id], $this->fingerprint($subject, $site, (array) $version->payload))) return true;
            }
            // A hostname change changes financial attribution even before republishing.
            $latest = $this->versions($site)->where('published_at', '<=', $period->ends_on->copy()->endOfDay())
                ->orderByDesc('published_at')->orderByDesc('id')->first();
            if ($period->ends_on->gte(now()->startOfDay())
                && ! hash_equals($review['sites'][$site->id], $this->fingerprint($subject, $site, (array) $latest?->payload))) return true;
        }
        return false;
    }

    public function fingerprint(DemandAccount|BidderAccount $subject, Site $site, array $payload): string
    {
        $sources = [];
        if ($subject instanceof DemandAccount) {
            $network = $subject->network->code->value;
            $demand = $payload['directDemand'] ?? $payload['nativeDemand'] ?? [];
            if ($demand['enabled'] ?? false) foreach ($demand['placements'] ?? [] as $placement) {
                if (! ($placement['enabled'] ?? false)) continue;
                foreach ($placement['candidates'] ?? [] as $candidate) {
                    if (($candidate['network'] ?? null) !== $network) continue;
                    $tag = $candidate['tag'] ?? [];
                    unset($tag['container']['id']);
                    $sources[] = $this->canonical(['mode' => $candidate['mode'] ?? null, 'gamManaged' => $candidate['gamManaged'] ?? false, 'tag' => $tag]);
                }
            }
        } else {
            $prebid = $payload['prebid'] ?? [];
            if ($prebid['enabled'] ?? false) foreach ($prebid['adUnits'] ?? [] as $unit) {
                foreach ($unit['bids'] ?? [] as $bid) {
                    if (($bid['bidder'] ?? null) === $subject->bidder->code) {
                        $sources[] = $this->canonical(['delivery' => $prebid['delivery']['mode'] ?? null, 'bid' => $bid]);
                    }
                }
            }
        }
        $sources = array_values(array_unique($sources));
        sort($sources, SORT_STRING);
        return hash('sha256', $this->canonical(['hostname' => app(SiteGamReportScope::class)->hostname($site->primary_domain), 'sources' => $sources]));
    }

    private function canonical(array $value): string
    {
        $sort = function (array $items) use (&$sort): array {
            if (! array_is_list($items)) ksort($items);
            foreach ($items as &$item) if (is_array($item)) $item = $sort($item);
            return $items;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR);
    }

    private function sites(DemandAccount|BidderAccount $subject)
    {
        $ids = $subject instanceof DemandAccount ? $subject->sites()->where('is_enabled', true)->pluck('site_id')
            : $subject->siteMappings()->where('enabled', true)->pluck('site_id');
        return Site::withoutGlobalScopes()->whereIn('id', $ids)->get();
    }

    private function versions(Site $site)
    {
        return ConfigVersion::withoutGlobalScopes()->where('organization_id', $site->organization_id)
            ->where('site_id', $site->id)->where('environment', 'PRODUCTION')->whereNotNull('published_at');
    }
}
