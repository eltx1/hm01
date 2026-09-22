<?php

namespace App\Services\Reporting;

use App\Models\DailyReport;
use App\Models\FinancialPeriod;
use App\Models\Site;
use App\Models\SiteGamReportBinding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class SiteGamFinancialCoverage
{
    public function coversSite(string $siteId, FinancialPeriod $period): bool
    {
        $site = Site::withoutGlobalScopes()->find($siteId);
        $bindings = $this->bindings($period)->with('connection')->where('site_id', $siteId)->get();
        if (! $site || $bindings->isEmpty()) {
            return false;
        }
        $from = CarbonImmutable::parse($period->starts_on)->max(CarbonImmutable::parse($site->created_at)->startOfDay());
        $to = CarbonImmutable::parse($period->ends_on);
        if ($from->gt($to)) {
            return true;
        }
        $connectionIds = $bindings->pluck('report_source_connection_id')->unique()->values();
        $days = DailyReport::withoutGlobalScopes()
            ->whereIn('report_source_connection_id', $connectionIds)
            ->whereHas('dimension', fn ($q) => $q->where('site_id', $siteId))
            ->where('currency', $period->currency)
            ->where('finality', 'FINALIZED')
            ->where('settlement_eligible', true)
            ->whereDate('report_date', '>=', $from->toDateString())
            ->whereDate('report_date', '<=', $to->toDateString())
            ->get(['report_source_connection_id', 'report_date'])
            ->mapWithKeys(fn ($report) => [
                $report->report_source_connection_id.'|'.$report->report_date->toDateString() => true,
            ]);

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $binding = $bindings->first(fn ($item) => $item->starts_on->toDateString() <= $date
                && (! $item->ends_on || $item->ends_on->toDateString() >= $date));

            // Explicit Site GAM coverage means this exact website binding must own
            // the date and currency. Another provider/source row can never fill a
            // missing Site GAM day or fabricate a cross-currency liability.
            if (! $binding || strtoupper((string) $binding->connection->currency) !== strtoupper((string) $period->currency)) {
                return false;
            }
            if (! $days->has($binding->report_source_connection_id.'|'.$date)) {
                return false;
            }
        }

        return true;
    }

    public function blockers(FinancialPeriod $period): Collection
    {
        return $this->bindings($period)->with('connection', 'site')->get()->map(function ($binding) use ($period): ?array {
            if ($binding->connection->currency !== $period->currency) {
                return null;
            }
            $from = CarbonImmutable::parse($period->starts_on)->max($binding->starts_on);
            $to = CarbonImmutable::parse($period->ends_on);
            if ($binding->ends_on) {
                $to = $to->min($binding->ends_on);
            }
            $days = DailyReport::withoutGlobalScopes()->where('report_source_connection_id', $binding->report_source_connection_id)
                ->where('currency', $period->currency)->where('finality', 'FINALIZED')->where('settlement_eligible', true)
                ->whereDate('report_date', '>=', $from->toDateString())->whereDate('report_date', '<=', $to->toDateString())->distinct()->count('report_date');
            if ($days === (int) $from->diffInDays($to) + 1) {
                return null;
            }

            return ['subject_type' => 'SITE_GAM_AD_UNIT', 'subject_id' => $binding->site_id,
                'subject_name' => $binding->site?->display_name ?? $binding->site_id, 'status' => 'STALE',
                'reasons' => [['code' => 'SITE_GAM_REPORT_COVERAGE_MISSING', 'message' => 'The website ad-unit source has not imported every finalized day in this period.']]];
        })->filter()->values();
    }

    private function bindings(FinancialPeriod $period)
    {
        return SiteGamReportBinding::withoutGlobalScopes()->whereDate('starts_on', '<=', $period->ends_on)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $period->starts_on));
    }
}
