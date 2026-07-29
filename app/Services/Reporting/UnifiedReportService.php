<?php

namespace App\Services\Reporting;

use App\Enums\ReportFinality;
use App\Enums\ReportSourceCode;
use App\Models\Advertiser;
use App\Models\AdvertiserInvoice;
use App\Models\AdvertiserReport;
use App\Models\Campaign;
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
        $query = $this->daily($from, $to, $currency);
        $rows = $query->with(['dimension.publisher', 'dimension.site', 'dimension.campaign', 'connection.source'])->get();

        $horusGam = $rows->filter(fn ($row) =>
            ($row->connection?->source?->code?->value ?? null) === ReportSourceCode::HorusGam->value
        );
        $adjustments = RevenueAdjustment::withoutGlobalScopes()
            ->where('status', 'APPROVED')
            ->whereDate('effective_on', '>=', $from->toDateString())
            ->whereDate('effective_on', '<=', $to->toDateString())
            ->when($currency, fn (Builder $query) => $query->where('currency', strtoupper($currency)))
            ->get();
        $adjustmentTotal = (int) $adjustments->sum('amount_minor');
        $publisherAdjustment = (int) $adjustments->sum(fn ($adjustment) => (int) data_get($adjustment->metadata, 'publisher_impact_minor', 0));
        $horusAdjustment = (int) $adjustments->sum(fn ($adjustment) => (int) data_get($adjustment->metadata, 'horus_impact_minor', 0));
        $mcmAdjustment = (int) $adjustments->sum(fn ($adjustment) => (int) data_get($adjustment->metadata, 'mcm_partner_impact_minor', 0));

        return [
            'from' => $from, 'to' => $to,
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
            'outstanding_publisher_payments_minor' => (int) PublisherStatement::withoutGlobalScopes()->sum('balance_due_minor'),
            'advertiser_balances_minor' => (int) AdvertiserInvoice::withoutGlobalScopes()->sum('balance_due_minor'),
            'unpaid_publisher_payments' => PublisherPayment::withoutGlobalScopes()
                ->whereNotIn('status', ['PAID', 'CANCELLED'])->count(),
        ];
    }

    public function publisherSummary(
        Publisher $publisher,
        CarbonInterface|string|null $from = null,
        CarbonInterface|string|null $to = null,
    ): array {
        [$from, $to] = $this->range($from, $to);
        $rows = $this->daily($from, $to)
            ->whereHas('dimension', fn (Builder $query) => $query->where('publisher_id', $publisher->id))
            ->with(['dimension.site', 'dimension.placement', 'connection.source'])
            ->get();
        $impressions = (int) $rows->sum('impressions');
        $revenue = (int) $rows->sum('publisher_earnings_minor');

        return [
            'from' => $from, 'to' => $to,
            'impressions' => $impressions,
            'revenue_minor' => $revenue,
            'ecpm_micros' => $impressions > 0 ? (int) round($revenue * 10000 / $impressions) : 0,
            'websites' => $this->group($rows, fn ($row) => $row->dimension?->site?->display_name ?? 'Unassigned'),
            'placements' => $this->group($rows, fn ($row) => $row->dimension?->placement?->name ?? 'Unassigned'),
            'payment_balance_minor' => (int) PublisherStatement::withoutGlobalScopes()
                ->where('publisher_id', $publisher->id)->sum('balance_due_minor'),
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
        $rows = AdvertiserReport::withoutGlobalScopes()
            ->where('advertiser_id', $advertiser->id)
            ->whereDate('report_date', '>=', $from->toDateString())
            ->whereDate('report_date', '<=', $to->toDateString())
            ->with(['campaign', 'connection.source'])
            ->get();
        $impressions = (int) $rows->sum('impressions');
        $clicks = (int) $rows->sum('clicks');

        return [
            'from' => $from, 'to' => $to,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr_bp' => $impressions > 0 ? (int) round($clicks * 10000 / $impressions) : 0,
            'spend_minor' => (int) $rows->sum('spend_minor'),
            'remaining_budget_minor' => max(0, (int) Campaign::withoutGlobalScopes()
                ->where('advertiser_id', $advertiser->id)->sum('total_budget_minor') - (int) $rows->sum('spend_minor')),
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
        $rows = AdvertiserReport::withoutGlobalScopes()->where('campaign_id', $campaign->id)->get();
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

    private function range(CarbonInterface|string|null $from, CarbonInterface|string|null $to): array
    {
        return [
            $from ? CarbonImmutable::parse($from)->startOfDay() : now()->startOfMonth()->toImmutable(),
            $to ? CarbonImmutable::parse($to)->endOfDay() : now()->endOfDay()->toImmutable(),
        ];
    }
}
