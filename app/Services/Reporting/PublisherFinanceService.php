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
use App\Models\PublisherPaymentProfile;
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
        $statementModels = $this->statementModels($publisher);
        $payments = $this->payments($publisher);
        $contract = $this->activeContract($publisher);
        $currencyCodes = $this->currencies($publisher, $statementModels, $payments, $contract);

        $currencies = $currencyCodes->map(function (string $currency) use ($publisher, $profile, $statementModels, $payments, $contract): array {
            $currentRows = DailyReport::withoutGlobalScopes()
                ->whereHas('dimension', fn (Builder $query) => $query->where('publisher_id', $publisher->id))
                ->where('currency', $currency)
                ->whereDate('report_date', '>=', now()->startOfMonth()->toDateString())
                ->whereDate('report_date', '<=', now()->toDateString())
                ->get();
            $todayCandidates = DailyReport::withoutGlobalScopes()
                ->whereHas('dimension', fn (Builder $query) => $query->where('publisher_id', $publisher->id))
                ->where('currency', $currency)
                ->where('finality', ReportFinality::Estimated->value)
                ->whereDate('report_date', '>=', now()->subDay()->toDateString())
                ->whereDate('report_date', '<=', now()->addDay()->toDateString())
                ->with('connection')
                ->get();
            $todayRows = $todayCandidates->filter(function (DailyReport $row): bool {
                $timezone = $row->connection?->timezone ?: config('reporting.default_timezone', 'UTC');

                return $row->report_date->toDateString() === now($timezone)->toDateString();
            });
            $currencyStatements = $statementModels->where('currency', $currency);
            $latest = $currencyStatements->first();
            $currencyPayments = $payments->where('currency', $currency);
            $pendingStatuses = [
                PublisherPaymentStatus::Pending,
                PublisherPaymentStatus::Approved,
                PublisherPaymentStatus::Scheduled,
                PublisherPaymentStatus::Processing,
                PublisherPaymentStatus::PartiallyPaid,
                PublisherPaymentStatus::Held,
            ];
            $pending = $currencyPayments->whereIn('status', $pendingStatuses);
            $threshold = $latest
                ? (int) $latest->payment_threshold_minor
                : $this->contractThreshold($contract, $currency);
            $readiness = $this->readiness($profile, $currency, $latest, $pending->isNotEmpty());

            return [
                'currency' => $currency,
                'today_available' => $todayRows->isNotEmpty(),
                'today_impressions' => (int) $todayRows->sum('impressions'),
                'today_clicks' => (int) $todayRows->sum('clicks'),
                'today_estimated_earnings_minor' => (int) $todayRows->sum('publisher_earnings_minor'),
                'today_updated_at' => $todayRows->sortByDesc('updated_at')->first()?->updated_at,
                'estimated_earnings_minor' => (int) $currentRows
                    ->where('finality', ReportFinality::Estimated)
                    ->sum('publisher_earnings_minor'),
                'finalized_earnings_minor' => (int) $currentRows
                    ->where('finality', ReportFinality::Finalized)
                    ->sum('publisher_earnings_minor'),
                'affiliate_earnings_minor' => (int) ($latest?->affiliate_earnings_minor ?? 0),
                'current_payable_minor' => $latest && $this->isPayable($latest)
                    ? (int) $latest->balance_due_minor
                    : 0,
                'below_threshold_minor' => $latest && in_array($latest->status, [
                    PublisherStatementStatus::BelowThreshold,
                    PublisherStatementStatus::CarriedForward,
                ], true) ? (int) $latest->balance_due_minor : 0,
                'opening_carry_forward_minor' => (int) ($latest?->opening_balance_minor ?? 0),
                'carry_forward_minor' => (int) ($latest?->carry_forward_minor ?? 0),
                'pending_payout_minor' => (int) $pending->sum(fn (PublisherPayment $payment): int => $payment->remainingAmountMinor()),
                'scheduled_payout_minor' => (int) $pending
                    ->where('status', PublisherPaymentStatus::Scheduled)
                    ->sum(fn (PublisherPayment $payment): int => $payment->remainingAmountMinor()),
                'paid_minor' => (int) $currencyPayments->sum('settled_amount_minor'),
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

        $actions = $this->actions($profile?->verification_status, $currencies, $payments);
        $publisherCurrencies = $currencies->map(fn (array $summary): array => $this->publisherCurrencySummary($summary))->values();

        return [
            'publisher' => $publisher,
            'profile' => $profile,
            'currencies' => $publisherCurrencies,
            'statements' => $statementModels->map(fn (PublisherStatement $statement): array => $this->publisherStatementSummary($statement))->values(),
            'payments' => $payments,
            'actions' => $actions,
        ];
    }

    public function dashboard(Publisher $publisher): array
    {
        $overview = $this->overview($publisher);
        $currency = strtoupper((string) config('reporting.dashboard_currency', 'USD'));
        $primary = $overview['currencies']->firstWhere('currency', $currency) ?? [
            'currency' => $currency,
            'today_available' => false,
            'today_impressions' => 0,
            'today_clicks' => 0,
            'today_estimated_earnings_minor' => 0,
            'estimated_earnings_minor' => 0,
            'finalized_earnings_minor' => 0,
            'statement_balance_due_minor' => 0,
            'paid_minor' => 0,
        ];
        $impressions = DailyReport::withoutGlobalScopes()
            ->whereHas('dimension', fn (Builder $query) => $query->where('publisher_id', $publisher->id))
            ->where('currency', $currency)
            ->where('finality', ReportFinality::Finalized->value)
            ->whereDate('report_date', '>=', now()->startOfMonth()->toDateString())
            ->whereDate('report_date', '<=', now()->toDateString())
            ->sum('impressions');

        return [
            'currency' => $currency,
            'impressions' => (int) $impressions,
            'today_available' => (bool) ($primary['today_available'] ?? false),
            'today_impressions' => (int) ($primary['today_impressions'] ?? 0),
            'today_clicks' => (int) ($primary['today_clicks'] ?? 0),
            'today_estimated_earnings_minor' => (int) ($primary['today_estimated_earnings_minor'] ?? 0),
            'estimated_earnings_minor' => (int) ($primary['estimated_earnings_minor'] ?? 0),
            'finalized_earnings_minor' => (int) ($primary['finalized_earnings_minor'] ?? 0),
            'payment_balance_minor' => (int) ($primary['statement_balance_due_minor'] ?? 0),
            'paid_minor' => (int) ($primary['paid_minor'] ?? 0),
            'has_legacy_currencies' => $overview['currencies']->contains(fn (array $summary): bool => $summary['currency'] !== $currency),
            'statements' => collect($overview['statements'])->where('currency', $currency)->values(),
        ];
    }

    public function statements(Publisher $publisher): Collection
    {
        return $this->statementModels($publisher)
            ->map(fn (PublisherStatement $statement): array => $this->publisherStatementSummary($statement))
            ->values();
    }

    public function statement(PublisherStatement $statement): array
    {
        $statement->loadMissing(['period', 'payments.settlements']);

        return [
            ...$this->publisherStatementSummary($statement),
            'opening_balance_minor' => (int) $statement->opening_balance_minor,
            'payment_threshold_minor' => (int) $statement->payment_threshold_minor,
            'has_publisher_invoice' => filled($statement->publisher_invoice_path),
            'publisher_invoice_number' => $statement->publisher_invoice_number,
            'publisher_invoice_uploaded_at' => $statement->publisher_invoice_uploaded_at,
            'publisher_invoice_review_reason' => $statement->publisher_invoice_review_reason,
            'line_items' => collect($statement->line_items ?? [])->map(function (array $line): array {
                $source = (string) ($line['source'] ?? '');
                $isAffiliate = $source === 'AFFILIATE';

                return [
                    'kind' => $isAffiliate ? 'AFFILIATE' : ($source === 'ADJUSTMENT' ? 'ADJUSTMENT' : 'PUBLISHER_EARNINGS'),
                    'site' => $line['site'] ?? null,
                    'description' => $line['description'] ?? null,
                    'impressions' => (int) ($line['impressions'] ?? 0),
                    'amount_minor' => (int) ($isAffiliate
                        ? ($line['affiliate_earnings_minor'] ?? 0)
                        : ($line['publisher_earnings_minor'] ?? 0)),
                    'affiliate_commission_rate_bp' => (int) ($line['affiliate_commission_rate_bp'] ?? 0),
                    'referred_publisher' => $line['referred_publisher'] ?? null,
                ];
            })->values()->all(),
            'payments' => $statement->payments->map(fn (PublisherPayment $payment): array => [
                'payment_number' => $payment->payment_number,
                'payment_method' => $payment->payment_method,
                'scheduled_on' => $payment->scheduled_on,
                'horus_payment_reference' => $payment->horus_payment_reference,
                'currency' => $payment->currency,
                'settled_amount_minor' => (int) $payment->settled_amount_minor,
                'amount_minor' => (int) $payment->amount_minor,
                'status' => $payment->status->value,
            ])->values()->all(),
        ];
    }

    private function statementModels(Publisher $publisher): Collection
    {
        return PublisherStatement::withoutGlobalScopes()
            ->where('publisher_id', $publisher->id)
            ->where('organization_id', $publisher->organization_id)
            ->with(['period', 'payments.settlements'])
            ->orderByDesc(FinancialPeriod::select('ends_on')
                ->whereColumn('financial_periods.id', 'publisher_statements.financial_period_id'))
            ->orderByDesc('created_at')
            ->get();
    }

    private function publisherCurrencySummary(array $summary): array
    {
        $latest = $summary['latest_statement'] ?? null;
        unset($summary['latest_statement']);
        $summary['latest_statement_id'] = $latest?->id;
        $summary['statement_balance_due_minor'] = (int) ($latest?->balance_due_minor ?? 0);

        return $summary;
    }

    private function publisherStatementSummary(PublisherStatement $statement): array
    {
        return [
            'id' => $statement->id,
            'statement_number' => $statement->statement_number,
            'period_key' => $statement->period?->period_key,
            'currency' => $statement->currency,
            'status' => $statement->status->value,
            'publisher_earnings_minor' => (int) $statement->publisher_earnings_minor,
            'affiliate_earnings_minor' => (int) $statement->affiliate_earnings_minor,
            'paid_minor' => (int) $statement->paid_minor,
            'balance_due_minor' => (int) $statement->balance_due_minor,
            'carry_forward_minor' => (int) $statement->carry_forward_minor,
            'publisher_invoice_status' => $statement->publisher_invoice_status->value,
            'finalized_at' => $statement->finalized_at,
        ];
    }

    public function payments(Publisher $publisher): Collection
    {
        return PublisherPayment::withoutGlobalScopes()
            ->where('publisher_id', $publisher->id)
            ->where('organization_id', $publisher->organization_id)
            ->with(['statement.period', 'settlements'])
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

        $canonical = strtoupper((string) config('reporting.dashboard_currency', 'USD'));

        // Currency navigation represents real financial records, not profile
        // preferences. A bank/profile or contract currency alone must not create
        // an empty "historical currency" section on the Publisher dashboard.
        return collect([
            $canonical,
            ...$reported,
            ...$statements->pluck('currency'),
            ...$payments->pluck('currency'),
        ])->filter()
            ->map(fn ($currency) => strtoupper((string) $currency))
            ->unique()
            ->sortBy(fn (string $currency): string => ($currency === $canonical ? '0-' : '1-').$currency)
            ->values();
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
        ?PublisherPaymentProfile $profile,
        string $currency,
        ?PublisherStatement $statement,
        bool $hasPendingPayment,
    ): array {
        if ($profile?->verification_status !== PublisherPaymentProfileStatus::Verified) {
            return ['ready' => false, 'code' => 'PAYMENT_PROFILE_REVIEW', 'label' => 'Payment profile verification required'];
        }
        if (strtoupper((string) $profile->currency) !== $currency) {
            return ['ready' => false, 'code' => 'PAYMENT_PROFILE_CURRENCY', 'label' => 'Verified payment profile currency does not match this balance'];
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
        if ($statement->publisher_invoice_status === PublisherInvoiceStatus::Received) {
            return ['ready' => false, 'code' => 'INVOICE_UNDER_REVIEW', 'label' => 'Publisher invoice is awaiting Finance validation'];
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
        if ($payments->contains(fn (PublisherPayment $payment) => $payment->status === PublisherPaymentStatus::Held)) {
            $actions[] = ['code' => 'PAYOUT_HELD', 'label' => 'A payout is on hold. Review its explanation; no earnings were removed by the hold.'];
        }

        return $actions === []
            ? [['code' => 'NONE', 'label' => 'No action is required from you right now.']]
            : $actions;
    }
}
