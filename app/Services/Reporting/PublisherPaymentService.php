<?php

namespace App\Services\Reporting;

use App\Enums\PublisherInvoiceStatus;
use App\Enums\PublisherPaymentProfileStatus;
use App\Enums\PublisherPaymentStatus;
use App\Enums\PublisherStatementStatus;
use App\Models\PublisherPayment;
use App\Models\PublisherPaymentProfile;
use App\Models\PublisherStatement;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PublisherPaymentService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function create(PublisherStatement $statement, int $amountMinor, array $attributes, User $actor): PublisherPayment
    {
        return DB::transaction(function () use ($statement, $amountMinor, $attributes, $actor): PublisherPayment {
            $statement = PublisherStatement::withoutGlobalScopes()->lockForUpdate()->findOrFail($statement->id);
            $profile = PublisherPaymentProfile::withoutGlobalScopes()
                ->where('publisher_id', $statement->publisher_id)
                ->where('organization_id', $statement->organization_id)
                ->first();
            if ($profile?->verification_status !== PublisherPaymentProfileStatus::Verified) {
                throw ValidationException::withMessages([
                    'payment_profile' => 'The Publisher payment destination must be verified before a payout is created.',
                ]);
            }
            if ($statement->publisher_invoice_status === PublisherInvoiceStatus::Required
                || $statement->publisher_invoice_status === PublisherInvoiceStatus::Rejected) {
                throw ValidationException::withMessages([
                    'publisher_invoice' => 'A valid Publisher invoice is required before a payable statement can be paid.',
                ]);
            }
            $reserved = (int) PublisherPayment::withoutGlobalScopes()
                ->where('publisher_statement_id', $statement->id)
                ->whereIn('status', [
                    PublisherPaymentStatus::Pending->value,
                    PublisherPaymentStatus::Approved->value,
                    PublisherPaymentStatus::Processing->value,
                ])->sum('amount_minor');
            $available = max(0, (int) $statement->balance_due_minor - $reserved);
            if ($amountMinor <= 0 || $amountMinor > $available) {
                throw ValidationException::withMessages([
                    'amount_minor' => 'Payment amount must be positive and cannot exceed the unreserved statement balance.',
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
                'settled_amount_minor' => 0,
                'payment_method' => $attributes['payment_method'] ?? $profile->payment_method,
                'scheduled_on' => $attributes['scheduled_on'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'publisher_message' => $attributes['publisher_message'] ?? null,
                'metadata' => $attributes['metadata'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->audit->record('reporting.publisher_payment.created', $statement->organization_id, $actor, $payment, newValues: [
                'payment_number' => $payment->payment_number,
                'amount_minor' => $amountMinor,
                'currency' => $statement->currency,
                'payment_method' => $payment->payment_method,
            ]);

            return $payment;
        });
    }

    public function approve(PublisherPayment $payment, User $actor): PublisherPayment
    {
        $payment->update(['status' => PublisherPaymentStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now()]);
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
                'settled_amount_minor' => $amount,
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
                ->sum('settled_amount_minor');
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
