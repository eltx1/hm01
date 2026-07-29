<?php

namespace App\Services\Reporting;

use App\Enums\PublisherPaymentStatus;
use App\Enums\PublisherStatementStatus;
use App\Models\PublisherPayment;
use App\Models\PublisherStatement;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PublisherPaymentService
{
    public function __construct(private readonly AuditRecorder $audit)
    {
    }

    public function create(PublisherStatement $statement, int $amountMinor, array $attributes, User $actor): PublisherPayment
    {
        if ($amountMinor <= 0 || $amountMinor > (int) $statement->balance_due_minor) {
            throw ValidationException::withMessages([
                'amount_minor' => 'Payment amount must be positive and cannot exceed the statement balance.',
            ]);
        }
        if (! $statement->publisher_invoice_path && $statement->balance_due_minor >= $statement->payment_threshold_minor) {
            throw ValidationException::withMessages([
                'publisher_invoice' => 'A publisher invoice is required before a payable statement can be paid.',
            ]);
        }

        $payment = PublisherPayment::withoutGlobalScopes()->create([
            'organization_id' => $statement->organization_id,
            'publisher_id' => $statement->publisher_id,
            'publisher_statement_id' => $statement->id,
            'payment_number' => 'HM-PAY-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'status' => PublisherPaymentStatus::Pending,
            'currency' => $statement->currency,
            'amount_minor' => $amountMinor,
            'payment_method' => $attributes['payment_method'] ?? null,
            'scheduled_on' => $attributes['scheduled_on'] ?? null,
            'notes' => $attributes['notes'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
            'created_by' => $actor->id,
        ]);

        $this->audit->record('reporting.publisher_payment.created', $statement->organization_id, $actor, $payment, newValues: [
            'payment_number' => $payment->payment_number,
            'amount_minor' => $amountMinor,
            'currency' => $statement->currency,
        ]);

        return $payment;
    }

    public function approve(PublisherPayment $payment, User $actor): PublisherPayment
    {
        $payment->update(['status' => PublisherPaymentStatus::Approved, 'approved_by' => $actor->id]);
        $this->audit->record('reporting.publisher_payment.approved', $payment->organization_id, $actor, $payment, newValues: [
            'status' => PublisherPaymentStatus::Approved->value,
        ]);
        return $payment->refresh();
    }

    public function markPaid(PublisherPayment $payment, string $reference, User $actor, ?int $settledAmountMinor = null): PublisherPayment
    {
        return DB::transaction(function () use ($payment, $reference, $actor, $settledAmountMinor): PublisherPayment {
            $payment = PublisherPayment::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->id);
            $statement = PublisherStatement::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->publisher_statement_id);
            $requestedAmount = (int) $payment->amount_minor;
            $amount = $settledAmountMinor ?? $requestedAmount;
            if ($amount <= 0 || $amount > $requestedAmount || $amount > (int) $statement->balance_due_minor) {
                throw ValidationException::withMessages(['amount_minor' => 'Settled amount is invalid for this payment and statement.']);
            }

            $payment->update([
                'amount_minor' => $amount,
                'status' => $amount < $requestedAmount
                    ? PublisherPaymentStatus::PartiallyPaid
                    : PublisherPaymentStatus::Paid,
                'horus_payment_reference' => $reference,
                'paid_at' => now(),
                'processed_by' => $actor->id,
            ]);

            $paid = (int) PublisherPayment::withoutGlobalScopes()
                ->where('publisher_statement_id', $statement->id)
                ->whereIn('status', [PublisherPaymentStatus::Paid->value, PublisherPaymentStatus::PartiallyPaid->value])
                ->sum('amount_minor');
            $balance = max(0, (int) $statement->opening_balance_minor + (int) $statement->publisher_earnings_minor - $paid);
            $statement->update([
                'paid_minor' => $paid,
                'balance_due_minor' => $balance,
                'carry_forward_minor' => $balance > 0 ? $balance : 0,
                'status' => $balance === 0
                    ? PublisherStatementStatus::Paid
                    : PublisherStatementStatus::PartiallyPaid,
            ]);

            $this->audit->record('reporting.publisher_payment.paid', $payment->organization_id, $actor, $payment, newValues: [
                'amount_minor' => $amount,
                'horus_payment_reference' => $reference,
                'statement_balance_minor' => $balance,
            ]);

            return $payment->refresh();
        });
    }
}
