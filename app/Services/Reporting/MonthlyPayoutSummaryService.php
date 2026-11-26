<?php

namespace App\Services\Reporting;

use App\Enums\ReportFinality;
use App\Models\DailyReport;
use App\Models\FinancialPeriod;
use App\Models\Publisher;
use App\Models\PublisherStatement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One simple answer for paying publishers: for one calendar month, each
 * publisher's gross revenue, net earnings (what the publisher is owed for that
 * month) and the Horus commission.
 *
 * Amounts come straight from the daily ledger, filtered by each fact's own
 * report date, so a month never includes another month's days. The same facts
 * and filters as the publisher's own "Reports & earnings" page are used
 * (completed imports, estimates included and flagged), so both screens agree.
 * Statement balances are shown beside the ledger, never added into it: a
 * statement can also carry unpaid below-threshold balances from earlier months.
 */
final class MonthlyPayoutSummaryService
{
    /** @return list<string> Selectable months (YYYY-MM), newest first: the current month and the 12 before it. */
    public function months(): array
    {
        $current = CarbonImmutable::now()->startOfMonth();

        return collect(range(0, 12))->map(fn (int $back): string => $current->subMonthsNoOverflow($back)->format('Y-m'))->all();
    }

    /** The requested month when valid and selectable, otherwise the last complete month. */
    public function resolveMonth(mixed $requested): string
    {
        $months = $this->months();

        return is_string($requested) && in_array($requested, $months, true) ? $requested : $months[1];
    }

    /** @return array<string, mixed> */
    public function summary(string $month): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) {
            throw new \InvalidArgumentException('Month must be formatted as YYYY-MM.');
        }
        $start = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $end = $start->endOfMonth();

        $ledger = $this->ledger($start, $end);
        $statements = $this->statements($month);
        $keys = $ledger->keys()->merge($statements->keys())->unique()->values();
        $publisherIds = $keys->map(fn (string $key): string => explode('|', $key, 2)[0])->unique()->values();
        $publishers = Publisher::withoutGlobalScopes()->whereIn('id', $publisherIds)
            ->with(['paymentProfile' => fn ($query) => $query->withoutGlobalScopes()])
            ->withCount(['sites' => fn ($query) => $query->withoutGlobalScopes()])
            ->get()->keyBy('id');

        $rows = $keys->map(function (string $key) use ($ledger, $statements, $publishers): ?array {
            [$publisherId, $currency] = explode('|', $key, 2);
            $publisher = $publishers->get($publisherId);
            if (! $publisher) {
                return null;
            }
            $fact = $ledger->get($key);
            $gross = (int) ($fact->gross_minor ?? 0);
            $earnings = (int) ($fact->publisher_minor ?? 0);
            $commission = (int) ($fact->horus_minor ?? 0);
            $statement = $statements->get($key);
            $profile = $publisher->paymentProfile;

            return [
                'publisher_id' => $publisher->id,
                'publisher' => $publisher->display_name ?: $publisher->legal_name,
                'sites' => (int) $publisher->sites_count,
                'currency' => $currency,
                'gross_minor' => $gross,
                'publisher_minor' => $earnings,
                'horus_minor' => $commission,
                // Gross minus both shares: demand-partner deductions, invalid traffic, other adjustments, MCM partner shares.
                'other_minor' => $gross - $earnings - $commission,
                'video_publisher_minor' => (int) ($fact->video_publisher_minor ?? 0),
                'estimated_publisher_minor' => (int) ($fact->estimated_publisher_minor ?? 0),
                'days' => (int) ($fact->days ?? 0),
                'final' => $fact !== null && (int) $fact->estimated_rows === 0,
                'statement' => $statement ? [
                    'status' => $statement->status->value,
                    'carried_in_minor' => (int) $statement->opening_balance_minor,
                    'month_earnings_minor' => (int) $statement->publisher_earnings_minor,
                    'affiliate_minor' => (int) $statement->affiliate_earnings_minor,
                    'balance_due_minor' => (int) $statement->balance_due_minor,
                    'paid_minor' => (int) $statement->paid_minor,
                    'matches_ledger' => (int) $statement->publisher_earnings_minor === $earnings,
                ] : null,
                'payment_details' => $profile ? $profile->verification_status->value : 'MISSING',
                'payment_method' => $profile?->payment_method,
            ];
        })->filter()->sortByDesc('publisher_minor')->values();

        $totals = $rows->groupBy('currency')->map(fn (Collection $group, string $currency): array => [
            'currency' => $currency,
            'publishers' => $group->count(),
            'gross_minor' => (int) $group->sum('gross_minor'),
            'publisher_minor' => (int) $group->sum('publisher_minor'),
            'horus_minor' => (int) $group->sum('horus_minor'),
            'other_minor' => (int) $group->sum('other_minor'),
            'estimated_publisher_minor' => (int) $group->sum('estimated_publisher_minor'),
        ])->sortKeys()->values();

        return [
            'month' => $month,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'complete' => $end->lt(CarbonImmutable::today()),
            'rows' => $rows,
            'totals' => $totals,
            'has_estimates' => $rows->contains(fn (array $row): bool => $row['estimated_publisher_minor'] !== 0),
            'mismatches' => $rows->filter(fn (array $row): bool => $row['statement'] !== null && ! $row['statement']['matches_ledger'])->count(),
        ];
    }

    /** @return Collection<string, object> keyed "publisherId|CURRENCY" */
    private function ledger(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $video = "(report_source_connections.connection_type = 'SITE_GAM_VIDEO_AD_UNIT' OR report_sources.code = 'GAM_VIDEO_AD_UNIT')";
        $estimated = "daily_reports.finality = '".ReportFinality::Estimated->value."'";

        return ReportDisplayQuery::constrain(DailyReport::withoutGlobalScopes(), true)
            ->join('report_dimensions', 'report_dimensions.id', '=', 'daily_reports.report_dimension_id')
            ->join('publishers', 'publishers.id', '=', 'report_dimensions.publisher_id')
            ->leftJoin('report_source_connections', 'report_source_connections.id', '=', 'daily_reports.report_source_connection_id')
            ->leftJoin('report_sources', 'report_sources.id', '=', 'report_source_connections.report_source_id')
            // Same ownership boundary as the publisher's own report.
            ->whereColumn('daily_reports.organization_id', 'publishers.organization_id')
            ->whereColumn('report_dimensions.organization_id', 'publishers.organization_id')
            ->whereDate('daily_reports.report_date', '>=', $start->toDateString())
            ->whereDate('daily_reports.report_date', '<=', $end->toDateString())
            ->groupBy('report_dimensions.publisher_id', 'daily_reports.currency')
            ->select([
                'report_dimensions.publisher_id',
                'daily_reports.currency',
                DB::raw('SUM(daily_reports.gross_revenue_minor) AS gross_minor'),
                DB::raw('SUM(daily_reports.publisher_earnings_minor) AS publisher_minor'),
                DB::raw('SUM(daily_reports.horus_earnings_minor) AS horus_minor'),
                DB::raw("SUM(CASE WHEN {$video} THEN daily_reports.publisher_earnings_minor ELSE 0 END) AS video_publisher_minor"),
                DB::raw("SUM(CASE WHEN {$estimated} THEN daily_reports.publisher_earnings_minor ELSE 0 END) AS estimated_publisher_minor"),
                DB::raw("SUM(CASE WHEN {$estimated} THEN 1 ELSE 0 END) AS estimated_rows"),
                DB::raw('COUNT(DISTINCT daily_reports.report_date) AS days'),
            ])
            ->get()
            ->keyBy(fn ($row): string => $row->publisher_id.'|'.strtoupper((string) $row->currency));
    }

    /** @return Collection<string, PublisherStatement> keyed "publisherId|CURRENCY" */
    private function statements(string $month): Collection
    {
        $periods = FinancialPeriod::query()->where('period_key', $month)->pluck('id');

        return PublisherStatement::withoutGlobalScopes()->whereIn('financial_period_id', $periods)->get()
            ->keyBy(fn (PublisherStatement $statement): string => $statement->publisher_id.'|'.strtoupper((string) $statement->currency));
    }
}
