<?php

namespace App\Services\Reporting;

use App\Enums\PublisherInvoiceStatus;
use App\Enums\PublisherPaymentProfileStatus;
use App\Enums\PublisherPaymentStatus;
use App\Enums\PublisherStatementStatus;
use App\Enums\ReportFinality;
use App\Models\DailyReport;
use App\Models\FinancialPeriod;
use App\Models\Publisher;
use App\Models\PublisherContract;
use App\Models\PublisherPayment;
use App\Models\PublisherStatement;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class PublisherFinanceService
{
    public function overview(Publisher $publisher): array
    {
        $profile = $publisher->paymentProfile;
        $statements = $this->statements($publisher);
        $payments = $this->payments($publisher);
        $contract = $this->activeContract($publisher);
        $currencyCodes = $this->currencies($publisher, $statements, $payments, $contract);

        $currencies = $currencyCodes->map(function (string $currency) use ($publisher, $profile, $statements, $payments, $contract): array {
            $currentRows = DailyReport::withoutGlobalScopes()
                ->whereHas('dimension', fn (Builder $query) => $query->where('publisher_id', $publisher->id))
                ->where('currency', $currency)
                ->whereDate('report_date', '>=', now()->startOfMonth()->toDateString())
                ->whereDate('report_date', '<=', now()->toDateString())
                ->get();
            $currencyStatements = $statements->where('currency', $currency);
            $latest = $currencyStatements->first();
            $currencyPayments = $payments->where('currency', $currency);
            $pendingStatuses = [
                PublisherPaymentStatus::Pending,
                PublisherPaymentStatus::Approved,
                PublisherPaymentStatus::Processing,
            ];
            $pending = $currencyPayments->whereIn('status', $pendingStatuses);
            $threshold = $latest
                ? (int) $latest->payment_threshold_minor
                : $this->contractThreshold($contract, $currency);
            $readiness = $this->readiness($profile?->verification_status, $latest, $pending->isNotEmpty());

            return [
                'currency' => $currency,
                'estimated_earnings_minor' => (int) $currentRows
                    ->where('finality', ReportFinality::Estimated)
                    ->sum('publisher_earnings_minor'),
                'finalized_earnings_minor' => (int) $currentRows
                    ->where('finality', ReportFinality::Finalized)
                    ->sum('publisher_earnings_minor'),
                'current_payable_minor' => $latest && $this->isPayable($latest)
                    ? (int) $latest->balance_due_minor
                    : 0,
                'below_threshold_minor' => $latest && in_array($latest->status, [
                    PublisherStatementStatus::BelowThreshold,
                    PublisherStatementStatus::CarriedForward,
                ], true) ? (int) $latest->balance_due_minor : 0,
                'opening_carry_forward_minor' => (int) ($latest?->opening_balance_minor ?? 0),
                'carry_forward_minor' => (int) ($latest?->carry_forward_minor ?? 0),
                'pending_payout_minor' => (int) $pending->sum('amount_minor'),
                'scheduled_payout_minor' => (int) $pending->whereNotNull('scheduled_on')->sum('amount_minor'),
                'paid_minor' => (int) $currencyPayments
                    ->whereIn('status', [PublisherPaymentStatus::Paid, PublisherPaymentStatus::PartiallyPaid])
                    ->sum('settled_amount_minor'),
                'payment_threshold_minor' => $threshold,
                'current_period' => now()->format('Y-m'),
                'current_period_status' => FinancialPeriod::query()
                    ->where('period_key', now()->format('Y-m'))
                    ->where('currency', $currency)
                    ->value('status') ?: 'NOT_OPENED',
                'last_finalized_period' => $latest?->period?->period_key,
                'latest_statement' => $latest,
                'readiness' => $readiness,
            ];
        })->values();

        return [
            'publisher' => $publisher,
            'profile' => $profile,
            'currencies' => $currencies,
            'statements' => $statements,
            'payments' => $payments,
            'actions' => $this->actions($profile?->verification_status, $currencies, $payments),
        ];
    }

    public function statements(Publisher $publisher): Collection
    {
        return PublisherStatement::withoutGlobalScopes()
            ->where('publisher_id', $publisher->id)
            ->where('organization_id', $publisher->organization_id)
            ->with(['period', 'payments'])
            ->orderByDesc(FinancialPeriod::select('ends_on')
                ->whereColumn('financial_periods.id', 'publisher_statements.financial_period_id'))
            ->orderByDesc('created_at')
            ->get();
    }

    public function payments(Publisher $publisher): Collection
    {
        return PublisherPayment::withoutGlobalScopes()
            ->where('publisher_id', $publisher->id)
            ->where('organization_id', $publisher->organization_id)
            ->with(['statement.period'])
            ->latest()
            ->get();
    }

    private function currencies(
        Publisher $publisher,
        Collection $statements,
        Collection $payments,
        ?PublisherContract $contract,
    ): Collection {
        $reported = DailyReport::withoutGlobalScopes()
            ->whereHas('dimension', fn (Builder $query) => $query->where('publisher_id', $publisher->id))
            ->distinct()
            ->pluck('currency');

        return collect([
            ...$reported,
            ...$statements->pluck('currency'),
            ...$payments->pluck('currency'),
            $contract?->currency,
            $publisher->paymentProfile?->currency,
        ])->filter()->map(fn ($currency) => strtoupper((string) $currency))->unique()->sort()->values()
            ->whenEmpty(fn (Collection $currencies) => $currencies->push(strtoupper((string) config('reporting.default_currency', 'USD'))));
    }

    private function activeContract(Publisher $publisher): ?PublisherContract
    {
        return PublisherContract::withoutGlobalScopes()
            ->where('publisher_id', $publisher->id)
            ->where('organization_id', $publisher->organization_id)
            ->where('status', 'ACTIVE')
            ->latest('starts_at')
            ->first();
    }

    private function contractThreshold(?PublisherContract $contract, string $currency): int
    {
        if (! $contract || strtoupper($contract->currency) !== $currency) {
            return 0;
        }

        try {
            return Money::decimalToMinor((string) $contract->payment_threshold);
        } catch (InvalidArgumentException) {
            return 0;
        }
    }

    private function readiness(
        ?PublisherPaymentProfileStatus $profileStatus,
        ?PublisherStatement $statement,
        bool $hasPendingPayment,
    ): array {
        if ($profileStatus !== PublisherPaymentProfileStatus::Verified) {
            return ['ready' => false, 'code' => 'PAYMENT_PROFILE_REVIEW', 'label' => 'Payment profile verification required'];
        }
        if (! $statement) {
            return ['ready' => false, 'code' => 'NO_FINALIZED_STATEMENT', 'label' => 'Awaiting a finalized statement'];
        }
        if ((int) $statement->balance_due_minor < (int) $statement->payment_threshold_minor) {
            return ['ready' => false, 'code' => 'BELOW_THRESHOLD', 'label' => 'Balance remains below the payment threshold'];
        }
        if (in_array($statement->publisher_invoice_status, [
            PublisherInvoiceStatus::Required,
            PublisherInvoiceStatus::Rejected,
        ], true)) {
            return ['ready' => false, 'code' => 'INVOICE_ACTION_REQUIRED', 'label' => 'Publisher invoice action required'];
        }
        if ($hasPendingPayment) {
            return ['ready' => false, 'code' => 'PAYOUT_IN_PROGRESS', 'label' => 'A payout is already pending or scheduled'];
        }

        return ['ready' => true, 'code' => 'READY', 'label' => 'Ready for Finance payout review'];
    }

    private function isPayable(PublisherStatement $statement): bool
    {
        return (int) $statement->balance_due_minor >= (int) $statement->payment_threshold_minor
            && ! in_array($statement->status, [PublisherStatementStatus::Paid, PublisherStatementStatus::BelowThreshold], true);
    }

    private function actions(
        ?PublisherPaymentProfileStatus $profileStatus,
        Collection $currencies,
        Collection $payments,
    ): array {
        $actions = [];
        if ($profileStatus === null || $profileStatus === PublisherPaymentProfileStatus::Incomplete) {
            $actions[] = ['code' => 'COMPLETE_PROFILE', 'label' => 'Complete your payment method.'];
        } elseif ($profileStatus === PublisherPaymentProfileStatus::Rejected) {
            $actions[] = ['code' => 'UPDATE_REJECTED_PROFILE', 'label' => 'Update the rejected payment profile and resubmit it.'];
        } elseif ($profileStatus === PublisherPaymentProfileStatus::NeedsUpdate) {
            $actions[] = ['code' => 'PROFILE_REVERIFICATION', 'label' => 'Your changed payment destination requires Finance re-verification.'];
        } elseif ($profileStatus === PublisherPaymentProfileStatus::PendingVerification) {
            $actions[] = ['code' => 'PROFILE_PENDING', 'label' => 'Finance is reviewing your payment profile; no further details are required now.'];
        }

        foreach ($currencies as $summary) {
            $invoiceStatus = $summary['latest_statement']?->publisher_invoice_status;
            if ($invoiceStatus === PublisherInvoiceStatus::Required) {
                $actions[] = ['code' => 'UPLOAD_INVOICE_'.$summary['currency'], 'label' => "Upload the required {$summary['currency']} Publisher invoice."];
            } elseif ($invoiceStatus === PublisherInvoiceStatus::Rejected) {
                $actions[] = ['code' => 'REPLACE_INVOICE_'.$summary['currency'], 'label' => "Replace the rejected {$summary['currency']} Publisher invoice."];
            }
        }
        if ($payments->contains(fn (PublisherPayment $payment) => $payment->status === PublisherPaymentStatus::Failed)) {
            $actions[] = ['code' => 'PAYOUT_FAILED', 'label' => 'Review the Publisher-visible payout failure message and update your payment method if requested.'];
        }

        return $actions === []
            ? [['code' => 'NONE', 'label' => 'No action is required from you right now.']]
            : $actions;
    }
}
