<?php

namespace App\Services\Reporting;

use App\Enums\PublisherPaymentStatus;
use App\Enums\ReportFinality;
use App\Enums\ReportSourceCode;
use App\Models\Advertiser;
use App\Models\AdvertiserInvoice;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\DailyReport;
use App\Models\Publisher;
use App\Models\PublisherPayment;
use App\Models\PublisherStatement;
use App\Models\RevenueAdjustment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class UnifiedReportService
{
    public function adminSummary(
        CarbonInterface|string|null $from = null,
        CarbonInterface|string|null $to = null,
        ?string $currency = null,
    ): array {
        [$from, $to] = $this->range($from, $to);
        $currency = $this->currency($currency);
        $query = $this->daily($from, $to, $currency);
        $rows = $query->with(['dimension.publisher', 'dimension.site', 'dimension.campaign', 'connection.source'])->get();

        $horusGam = $rows->filter(fn ($row) => ($row->connection?->source?->code?->value ?? null) === ReportSourceCode::HorusGam->value
        );
        $adjustments = RevenueAdjustment::withoutGlobalScopes()
            ->where('status', 'APPROVED')
            ->whereDate('effective_on', '>=', $from->toDateString())
            ->whereDate('effective_on', '<=', $to->toDateString())
            ->where('currency', $currency)
            ->get();
        $adjustmentTotal = (int) $adjustments->sum('amount_minor');
        $publisherAdjustment = (int) $adjustments->sum(fn ($adjustment) => (int) data_get($adjustment->metadata, 'publisher_impact_minor', 0));
        $horusAdjustment = (int) $adjustments->sum(fn ($adjustment) => (int) data_get($adjustment->metadata, 'horus_impact_minor', 0));
        $mcmAdjustment = (int) $adjustments->sum(fn ($adjustment) => (int) data_get($adjustment->metadata, 'mcm_partner_impact_minor', 0));

        return [
            'from' => $from, 'to' => $to, 'currency' => $currency,
            'managed_impressions' => (int) $rows->sum('impressions'),
            'horus_gam_impressions' => (int) $horusGam->sum('impressions'),
            'gross_revenue_minor' => (int) $rows->sum('gross_revenue_minor'),
            'net_revenue_minor' => max(0, (int) $rows->sum('net_revenue_minor') - $adjustmentTotal),
            'publisher_earnings_minor' => max(0, (int) $rows->sum('publisher_earnings_minor') - $publisherAdjustment),
            'horus_margin_minor' => max(0, (int) $rows->sum('horus_earnings_minor') - $horusAdjustment),
            'mcm_partner_earnings_minor' => max(0, (int) $rows->sum('mcm_partner_earnings_minor') - $mcmAdjustment),
            'approved_adjustments_minor' => $adjustmentTotal,
            'revenue_by_publisher' => $this->group($rows, fn ($row) => $row->dimension?->publisher?->display_name ?? 'Unassigned'),
            'revenue_by_website' => $this->group($rows, fn ($row) => $row->dimension?->site?->display_name ?? 'Unassigned'),
            'revenue_by_source' => $this->group($rows, fn ($row) => $row->connection?->source?->name ?? 'Unknown'),
            'revenue_by_campaign' => $this->group($rows, fn ($row) => $row->dimension?->campaign?->name ?? 'Non-campaign'),
            'outstanding_publisher_payments_minor' => (int) $this->latestPublisherStatements($currency)->sum('balance_due_minor'),
            'advertiser_balances_minor' => (int) AdvertiserInvoice::withoutGlobalScopes()
                ->where('currency', $currency)->sum('balance_due_minor'),
            'unpaid_publisher_payments' => PublisherPayment::withoutGlobalScopes()
                ->where('currency', $currency)
                ->whereIn('status', collect(PublisherPaymentStatus::cases())
                    ->filter(fn (PublisherPaymentStatus $status): bool => $status->reservesBalance())
                    ->map->value->all())
                ->count(),
        ];
    }

    public function publisherSummary(
        Publisher $publisher,
        CarbonInterface|string|null $from = null,
        CarbonInterface|string|null $to = null,
        ?string $currency = null,
    ): array {
        [$from, $to] = $this->range($from, $to);
        $currency = $this->currency($currency);
        $rows = $this->daily($from, $to, $currency)
            ->whereHas('dimension', fn (Builder $query) => $query->where('publisher_id', $publisher->id))
            ->with(['dimension.site', 'dimension.placement'])
            ->get();
        $impressions = (int) $rows->sum('impressions');
        $revenue = (int) $rows->sum('publisher_earnings_minor');

        return [
            'from' => $from, 'to' => $to, 'currency' => $currency,
            'impressions' => $impressions,
            'revenue_minor' => $revenue,
            'ecpm_micros' => $impressions > 0 ? (int) round($revenue * 10000 / $impressions) : 0,
            'websites' => $this->publisherGroup($rows, fn ($row) => $row->dimension?->site?->display_name ?? 'Unassigned'),
            'placements' => $this->publisherGroup($rows, fn ($row) => $row->dimension?->placement?->name ?? 'Unassigned'),
            'payment_balance_minor' => (int) ($this->latestPublisherStatements($currency, $publisher->id)
                ->first()?->balance_due_minor ?? 0),
            'statements' => PublisherStatement::withoutGlobalScopes()
                ->where('publisher_id', $publisher->id)->with('period')->latest()->limit(24)->get(),
        ];
    }

    public function advertiserSummary(
        Advertiser $advertiser,
        CarbonInterface|string|null $from = null,
        CarbonInterface|string|null $to = null,
    ): array {
        [$from, $to] = $this->range($from, $to);
        $campaigns = Campaign::withoutGlobalScopes()
            ->where('advertiser_id', $advertiser->id)
            ->get(['id', 'name', 'total_budget_minor', 'currency']);

        // CampaignDeliveryLog is authoritative when the campaign sync path has
        // recorded a given campaign/day. AdvertiserReport remains a valid
        // fallback for imported reporting sources that do not pass through the
        // campaign sync service. Prefer per campaign/day so the two stores are
        // never summed twice.
        $deliveryRows = CampaignDeliveryLog::withoutGlobalScopes()
            ->whereIn('campaign_id', $campaigns->pluck('id'))
            ->whereDate('report_date', '>=', $from->toDateString())
            ->whereDate('report_date', '<=', $to->toDateString())
            ->with('campaign')
            ->get();
        $advertiserRows = AdvertiserReport::withoutGlobalScopes()
            ->where('advertiser_id', $advertiser->id)
            ->whereDate('report_date', '>=', $from->toDateString())
            ->whereDate('report_date', '<=', $to->toDateString())
            ->with('campaign')
            ->get();

        $deliveryDays = $deliveryRows->map(
            fn ($row): string => $row->campaign_id.'|'.$row->report_date->toDateString()
        )->unique()->flip();
        $rows = $deliveryRows->concat($advertiserRows->reject(
            fn ($row): bool => $deliveryDays->has($row->campaign_id.'|'.$row->report_date->toDateString())
        ));

        $impressions = (int) $rows->sum('impressions');
        $clicks = (int) $rows->sum('clicks');
        $spend = (int) $rows->sum('spend_minor');

        return [
            'from' => $from, 'to' => $to,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr_bp' => $impressions > 0 ? (int) round($clicks * 10000 / $impressions) : 0,
            'spend_minor' => $spend,
            'remaining_budget_minor' => max(0, (int) $campaigns->sum('total_budget_minor') - $spend),
            'campaigns' => $rows->groupBy('campaign_id')->map(function (Collection $group): array {
                $campaign = $group->first()->campaign;
                $impressions = (int) $group->sum('impressions');
                $clicks = (int) $group->sum('clicks');

                return [
                    'campaign_id' => $campaign?->id,
                    'campaign' => $campaign?->name ?? 'Unknown',
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'ctr_bp' => $impressions > 0 ? (int) round($clicks * 10000 / $impressions) : 0,
                    'spend_minor' => (int) $group->sum('spend_minor'),
                ];
            })->values(),
            'invoices' => AdvertiserInvoice::withoutGlobalScopes()
                ->where('advertiser_id', $advertiser->id)->latest()->limit(24)->get(),
        ];
    }

    public function campaignCost(Campaign $campaign): array
    {
        $rows = CampaignDeliveryLog::withoutGlobalScopes()->where('campaign_id', $campaign->id)->get();
        if ($rows->isNotEmpty()) {
            $canonical = strtoupper(trim((string) config('reporting.canonical_currency', 'USD')));
            $canonical = preg_match('/^[A-Z]{3}$/D', $canonical) === 1 ? $canonical : 'USD';
            if (strtoupper((string) $campaign->currency) !== $canonical) {
                throw new \RuntimeException(
                    "Campaign delivery is denominated in {$canonical}; invoice synchronization requires an explicit FX ledger for {$campaign->currency}."
                );
            }
        }
        $impressions = (int) $rows->sum('impressions');
        $clicks = (int) $rows->sum('clicks');
        $spend = (int) $rows->sum('spend_minor');

        return [
            'campaign_id' => $campaign->id,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr_bp' => $impressions > 0 ? (int) round($clicks * 10000 / $impressions) : 0,
            'spend_minor' => $spend,
            'remaining_budget_minor' => max(0, (int) $campaign->total_budget_minor - $spend),
            'daily' => $rows->groupBy(fn ($row) => $row->report_date->toDateString())
                ->map(fn (Collection $group, $date) => [
                    'date' => $date,
                    'impressions' => (int) $group->sum('impressions'),
                    'clicks' => (int) $group->sum('clicks'),
                    'spend_minor' => (int) $group->sum('spend_minor'),
                ])->values()->all(),
        ];
    }

    private function daily(CarbonImmutable $from, CarbonImmutable $to, ?string $currency = null): Builder
    {
        return DailyReport::withoutGlobalScopes()
            ->where('finality', ReportFinality::Finalized->value)
            ->whereDate('report_date', '>=', $from->toDateString())
            ->whereDate('report_date', '<=', $to->toDateString())
            ->when($currency, fn (Builder $query) => $query->where('currency', strtoupper($currency)));
    }

    private function latestPublisherStatements(string $currency, ?string $publisherId = null): Collection
    {
        return PublisherStatement::withoutGlobalScopes()
            ->latestPerPublisherCurrency()
            ->where('currency', $currency)
            ->when($publisherId, fn (Builder $query) => $query->where('publisher_id', $publisherId))
            ->with('period')
            ->get();
    }

    private function currency(?string $currency): string
    {
        $currency = strtoupper(trim((string) ($currency ?: config('reporting.default_currency', 'USD'))));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'USD';
    }

    private function group(Collection $rows, callable $key): Collection
    {
        return $rows->groupBy($key)->map(fn (Collection $group, $label) => [
            'label' => $label,
            'impressions' => (int) $group->sum('impressions'),
            'gross_revenue_minor' => (int) $group->sum('gross_revenue_minor'),
            'net_revenue_minor' => (int) $group->sum('net_revenue_minor'),
            'publisher_earnings_minor' => (int) $group->sum('publisher_earnings_minor'),
            'horus_earnings_minor' => (int) $group->sum('horus_earnings_minor'),
        ])->sortByDesc('gross_revenue_minor')->values();
    }

    private function publisherGroup(Collection $rows, callable $key): Collection
    {
        return $rows->groupBy($key)->map(fn (Collection $group, $label) => [
            'label' => $label,
            'impressions' => (int) $group->sum('impressions'),
            'publisher_earnings_minor' => (int) $group->sum('publisher_earnings_minor'),
        ])->sortByDesc('publisher_earnings_minor')->values();
    }

    private function range(CarbonInterface|string|null $from, CarbonInterface|string|null $to): array
    {
        return [
            $from ? CarbonImmutable::parse($from)->startOfDay() : now()->startOfMonth()->toImmutable(),
            $to ? CarbonImmutable::parse($to)->endOfDay() : now()->endOfDay()->toImmutable(),
        ];
    }
}
