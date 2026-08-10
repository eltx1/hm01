<?php

namespace App\Services\Reporting;

use App\Enums\FinancialPeriodStatus;
use App\Enums\PublisherInvoiceStatus;
use App\Enums\PublisherPaymentProfileStatus;
use App\Enums\PublisherPaymentStatus;
use App\Enums\PublisherStatementStatus;
use App\Enums\ReconciliationStatus;
use App\Models\FinancialPeriod;
use App\Models\Publisher;
use App\Models\PublisherPayment;
use App\Models\PublisherPaymentProfile;
use App\Models\PublisherPaymentSettlement;
use App\Models\PublisherStatement;
use App\Models\ReconciliationRun;
use Illuminate\Support\Collection;

final class AdminFinanceService
{
    public function __construct(private readonly FinancialPeriodService $periods) {}

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $statements = PublisherStatement::withoutGlobalScopes()
            ->latestPerPublisherCurrency()
            ->with(['publisher.paymentProfile', 'payments', 'period'])
            ->get();
        $liabilityStatements = $statements;
        $payments = PublisherPayment::withoutGlobalScopes()->get();
        $settlements = PublisherPaymentSettlement::withoutGlobalScopes()
            ->whereBetween('settled_on', [now()->startOfMonth(), now()->endOfMonth()])
            ->get();
        $currencies = $liabilityStatements->pluck('currency')
            ->merge($payments->pluck('currency'))
            ->merge($settlements->pluck('currency'))
            ->filter()->map(fn ($currency): string => strtoupper((string) $currency))->unique()->sort()->values();

        $currencyTotals = $currencies->mapWithKeys(function (string $currency) use ($liabilityStatements, $payments, $settlements): array {
            $currencyStatements = $liabilityStatements->where('currency', $currency);
            $currencyPayments = $payments->where('currency', $currency);
            $eligible = $currencyStatements->filter(fn (PublisherStatement $statement): bool => $this->isEligible($statement));

            return [$currency => [
                'outstanding_liability_minor' => (int) $currencyStatements->sum('balance_due_minor'),
                'ready_for_payout_minor' => (int) $eligible->sum(fn (PublisherStatement $statement): int => $this->unreservedBalance($statement)),
                'below_threshold_minor' => (int) $currencyStatements
                    ->whereIn('status', [PublisherStatementStatus::BelowThreshold, PublisherStatementStatus::CarriedForward])
                    ->sum('carry_forward_minor'),
                'awaiting_invoice_minor' => (int) $currencyStatements
                    ->whereIn('publisher_invoice_status', [PublisherInvoiceStatus::Required, PublisherInvoiceStatus::Rejected])
                    ->sum('balance_due_minor'),
                'pending_approval_minor' => (int) $currencyPayments
                    ->where('status', PublisherPaymentStatus::Pending)
                    ->sum(fn (PublisherPayment $payment): int => $payment->remainingAmountMinor()),
                'approved_minor' => (int) $currencyPayments
                    ->where('status', PublisherPaymentStatus::Approved)
                    ->sum(fn (PublisherPayment $payment): int => $payment->remainingAmountMinor()),
                'scheduled_minor' => (int) $currencyPayments
                    ->where('status', PublisherPaymentStatus::Scheduled)
                    ->sum(fn (PublisherPayment $payment): int => $payment->remainingAmountMinor()),
                'paid_this_month_minor' => (int) $settlements->where('currency', $currency)->sum('amount_minor'),
                'partial_remaining_minor' => (int) $currencyPayments
                    ->filter(fn (PublisherPayment $payment): bool => (int) $payment->settled_amount_minor > 0 && $payment->remainingAmountMinor() > 0)
                    ->sum(fn (PublisherPayment $payment): int => $payment->remainingAmountMinor()),
                'failed_or_held_minor' => (int) $currencyPayments
                    ->whereIn('status', [PublisherPaymentStatus::Failed, PublisherPaymentStatus::Held])
                    ->sum(fn (PublisherPayment $payment): int => $payment->remainingAmountMinor()),
            ]];
        });

        $periodRows = $this->periodRows(12);
        $openPeriodRows = $periodRows->filter(fn (array $row): bool => $row['period']->status === FinancialPeriodStatus::Open);
        $profileCounts = PublisherPaymentProfile::withoutGlobalScopes()
            ->selectRaw('verification_status, COUNT(*) AS aggregate')
            ->groupBy('verification_status')
            ->pluck('aggregate', 'verification_status')
            ->map(fn ($value): int => (int) $value);
        $publishersWithoutProfile = Publisher::withoutGlobalScopes()
            ->whereDoesntHave('paymentProfile')->count();

        return [
            'currency_totals' => $currencyTotals,
            'counts' => [
                'profiles_incomplete' => $publishersWithoutProfile + (int) ($profileCounts[PublisherPaymentProfileStatus::Incomplete->value] ?? 0),
                'profiles_pending' => (int) ($profileCounts[PublisherPaymentProfileStatus::PendingVerification->value] ?? 0),
                'profiles_needs_update' => (int) ($profileCounts[PublisherPaymentProfileStatus::NeedsUpdate->value] ?? 0),
                'profiles_rejected' => (int) ($profileCounts[PublisherPaymentProfileStatus::Rejected->value] ?? 0),
                'partial_payments' => $payments->filter(fn (PublisherPayment $payment): bool => (int) $payment->settled_amount_minor > 0 && $payment->remainingAmountMinor() > 0)->count(),
                'failed_payouts' => $payments->where('status', PublisherPaymentStatus::Failed)->count(),
                'held_payouts' => $payments->where('status', PublisherPaymentStatus::Held)->count(),
                'open_periods' => $openPeriodRows->count(),
                'periods_ready' => $openPeriodRows->filter(fn (array $row): bool => (bool) $row['readiness']['ready'])->count(),
                'periods_blocked' => $openPeriodRows->filter(fn (array $row): bool => ! (bool) $row['readiness']['ready'])->count(),
                'reconciliation_discrepancies' => ReconciliationRun::withoutGlobalScopes()
                    ->whereIn('status', [ReconciliationStatus::Warning->value, ReconciliationStatus::Failed->value])->count(),
            ],
            'periods' => $periodRows,
        ];
    }

    /** @return Collection<int, array{period: FinancialPeriod, readiness: array<string, mixed>}> */
    public function periodRows(int $limit = 24): Collection
    {
        return FinancialPeriod::query()->latest('starts_on')->limit($limit)->get()
            ->map(fn (FinancialPeriod $period): array => [
                'period' => $period,
                'readiness' => $period->isClosed()
                    ? ($period->readiness_snapshot ?? ['ready' => true, 'blockers' => [], 'counts' => []])
                    : $this->periods->readiness($period),
            ]);
    }

    public function isEligible(PublisherStatement $statement): bool
    {
        if (! in_array($statement->status, [PublisherStatementStatus::Payable, PublisherStatementStatus::PartiallyPaid], true)) {
            return false;
        }
        if ($statement->invoiceRequired() && $statement->publisher_invoice_status !== PublisherInvoiceStatus::Accepted) {
            return false;
        }
        $profile = $statement->publisher?->paymentProfile;

        return $profile?->verification_status === PublisherPaymentProfileStatus::Verified
            && strtoupper((string) $profile->currency) === strtoupper((string) $statement->currency)
            && $this->unreservedBalance($statement) > 0;
    }

    public function unreservedBalance(PublisherStatement $statement): int
    {
        $payments = $statement->relationLoaded('payments')
            ? $statement->payments
            : $statement->payments()->get();
        $reserved = (int) $payments
            ->filter(fn (PublisherPayment $payment): bool => $payment->status->reservesBalance())
            ->sum(fn (PublisherPayment $payment): int => $payment->remainingAmountMinor());

        return max(0, (int) $statement->balance_due_minor - $reserved);
    }
}
