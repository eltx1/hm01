<?php

namespace App\Services\MobileApps;

use App\Models\{DailyReport, FinancialPeriod, MobileAppReportBinding};
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class MobileAppFinancialCoverage
{
    public function blockers(FinancialPeriod $period): Collection
    {
        return MobileAppReportBinding::withoutGlobalScopes()->with('connection', 'mobileApp')
            ->whereDate('starts_on', '<=', $period->ends_on)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $period->starts_on))
            ->get()->map(function ($binding) use ($period) {
                if ($binding->connection->currency !== $period->currency) return null;
                $from = CarbonImmutable::parse($period->starts_on)->max($binding->starts_on);
                $to = CarbonImmutable::parse($period->ends_on);
                if ($binding->ends_on) $to = $to->min($binding->ends_on);
                if ($from->gt($to)) return null;
                $days = DailyReport::withoutGlobalScopes()->where('report_source_connection_id', $binding->report_source_connection_id)
                    ->where('finality', 'FINALIZED')->where('settlement_eligible', true)->where('currency', $period->currency)
                    ->whereHas('dimension', fn ($q) => $q->where('mobile_app_id', $binding->mobile_app_id))
                    ->whereDate('report_date', '>=', $from->toDateString())->whereDate('report_date', '<=', $to->toDateString())
                    ->distinct()->count('report_date');
                if ($days === (int) $from->diffInDays($to) + 1) return null;
                return ['subject_type' => 'MOBILE_APP_GAM', 'subject_id' => $binding->mobile_app_id,
                    'subject_name' => $binding->mobileApp?->name, 'status' => 'STALE',
                    'reasons' => [['code' => 'MOBILE_APP_REPORT_COVERAGE_MISSING', 'message' => 'The app ad unit has not imported every finalized day in this period.']]];
            })->filter()->values();
    }
}
