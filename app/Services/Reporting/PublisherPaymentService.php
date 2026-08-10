<?php

namespace App\Services\Reporting;

use App\Enums\PublisherInvoiceStatus;
use App\Enums\PublisherPaymentProfileStatus;
use App\Enums\PublisherPaymentStatus;
use App\Enums\PublisherStatementStatus;
use App\Models\PublisherPayment;
use App\Models\PublisherPaymentProfile;
use App\Models\PublisherPaymentSettlement;
use App\Models\PublisherStatement;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PublisherPaymentService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function create(PublisherStatement $statement, int $amountMinor, array $attributes, User $actor): PublisherPayment
    {
        $this->authorize($actor, 'finance.payments.create');
        $idempotencyKey = trim((string) ($attributes['idempotency_key'] ?? Str::ulid()));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid payout idempotency key is required.']);
        }

        return DB::transaction(function () use ($statement, $amountMinor, $attributes, $actor, $idempotencyKey): PublisherPayment {
            $statement = PublisherStatement::withoutGlobalScopes()->lockForUpdate()->findOrFail($statement->id);
            $existing = PublisherPayment::withoutGlobalScopes()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                if ($existing->publisher_statement_id !== $statement->id || (int) $existing->amount_minor !== $amountMinor) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'This payout idempotency key was already used for different payment instructions.',
                    ]);
                }

                return $existing;
            }

            $profile = $this->verifiedProfile($statement, true);
            $this->assertStatementEligible($statement);
            if (isset($attributes['payment_method'])
                && (string) $attributes['payment_method'] !== (string) $profile->payment_method) {
                throw ValidationException::withMessages([
                    'payment_method' => 'Payout method must match the verified payment profile.',
                ]);
            }

            $activePayments = PublisherPayment::withoutGlobalScopes()
                ->where('publisher_statement_id', $statement->id)
                ->whereIn('status', $this->reservingStatuses())
                ->lockForUpdate()
                ->get();
            $reserved = (int) $activePayments->sum(fn (PublisherPayment $payment): int => $payment->remainingAmountMinor());
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
                'idempotency_key' => $idempotencyKey,
                'status' => PublisherPaymentStatus::Pending,
                'currency' => $statement->currency,
                'amount_minor' => $amountMinor,
                'settled_amount_minor' => 0,
                'payment_method' => $attributes['payment_method'] ?? $profile->payment_method,
                'scheduled_on' => null,
                'notes' => $attributes['notes'] ?? null,
                'publisher_message' => $attributes['publisher_message'] ?? null,
                'metadata' => $attributes['metadata'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->audit->record('finance.payout.created', $statement->organization_id, $actor, $payment, newValues: [
                'payment_number' => $payment->payment_number,
                'statement_number' => $statement->statement_number,
                'amount_minor' => $amountMinor,
                'currency' => $statement->currency,
                'payment_method' => $payment->payment_method,
                'idempotency_key' => $idempotencyKey,
            ]);

            return $payment;
        });
    }

    public function approve(PublisherPayment $payment, User $actor): PublisherPayment
    {
        $this->authorize($actor, 'finance.payments.approve');

        return DB::transaction(function () use ($payment, $actor): PublisherPayment {
            $payment = PublisherPayment::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status === PublisherPaymentStatus::Approved) {
                return $payment;
            }
            if ($payment->status !== PublisherPaymentStatus::Pending) {
                throw ValidationException::withMessages(['status' => 'Only a pending payout can be approved.']);
            }
            if ($payment->created_by === $actor->id) {
                throw ValidationException::withMessages(['approval' => 'The payout creator cannot approve the same payout.']);
            }
            $statement = PublisherStatement::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->publisher_statement_id);
            $this->assertPaymentGraph($payment, $statement);
            $this->verifiedProfile($statement, true);
            $this->assertStatementEligible($statement);

            $payment->update([
                'status' => PublisherPaymentStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);
            $this->audit->record('finance.payout.approved', $payment->organization_id, $actor, $payment, [
                'status' => PublisherPaymentStatus::Pending->value,
            ], [
                'status' => PublisherPaymentStatus::Approved->value,
                'approved_by' => $actor->id,
            ]);

            return $payment->refresh();
        });
    }

    public function schedule(PublisherPayment $payment, CarbonInterface|string $scheduledOn, User $actor): PublisherPayment
    {
        $this->authorize($actor, 'finance.payments.settle');

        return DB::transaction(function () use ($payment, $scheduledOn, $actor): PublisherPayment {
            $payment = PublisherPayment::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->id);
            $date = CarbonImmutable::parse($scheduledOn)->toDateString();
            if ($date < today()->toDateString()) {
                throw ValidationException::withMessages(['scheduled_on' => 'A payout cannot be scheduled in the past.']);
            }
            if ($payment->status === PublisherPaymentStatus::Scheduled && $payment->scheduled_on?->toDateString() === $date) {
                return $payment;
            }
            if ($payment->status !== PublisherPaymentStatus::Approved) {
                throw ValidationException::withMessages(['status' => 'Only an approved payout can be scheduled.']);
            }
            $payment->update(['status' => PublisherPaymentStatus::Scheduled, 'scheduled_on' => $date]);
            $this->audit->record('finance.payout.scheduled', $payment->organization_id, $actor, $payment, newValues: [
                'status' => PublisherPaymentStatus::Scheduled->value,
                'scheduled_on' => $date,
            ]);

            return $payment->refresh();
        });
    }

    public function beginExternalProcessing(PublisherPayment $payment, User $actor): PublisherPayment
    {
        $this->authorize($actor, 'finance.payments.settle');

        return DB::transaction(function () use ($payment, $actor): PublisherPayment {
            $payment = PublisherPayment::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status === PublisherPaymentStatus::Processing) {
                return $payment;
            }
            if (! in_array($payment->status, [PublisherPaymentStatus::Approved, PublisherPaymentStatus::Scheduled], true)) {
                throw ValidationException::withMessages(['status' => 'Only an approved or scheduled payout can enter external processing.']);
            }
            $before = $payment->status;
            $payment->update(['status' => PublisherPaymentStatus::Processing, 'processed_by' => $actor->id]);
            $this->audit->record('finance.payout.processing_started', $payment->organization_id, $actor, $payment, [
                'status' => $before->value,
            ], [
                'status' => PublisherPaymentStatus::Processing->value,
                'external_money_moved' => false,
            ]);

            return $payment->refresh();
        });
    }

    public function recordSettlement(
        PublisherPayment $payment,
        string $reference,
        int $amountMinor,
        CarbonInterface|string $settledOn,
        User $actor,
    ): PublisherPayment {
        $this->authorize($actor, 'finance.payments.settle');
        $reference = trim($reference);
        if ($reference === '' || mb_strlen($reference) > 255) {
            throw ValidationException::withMessages(['settlement_reference' => 'A real settlement reference is required.']);
        }
        $settledOn = CarbonImmutable::parse($settledOn);
        if ($settledOn->isFuture()) {
            throw ValidationException::withMessages(['settled_on' => 'A settlement date cannot be in the future.']);
        }

        try {
            return DB::transaction(function () use ($payment, $reference, $amountMinor, $settledOn, $actor): PublisherPayment {
                $payment = PublisherPayment::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->id);
                $statement = PublisherStatement::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->publisher_statement_id);
                $this->assertPaymentGraph($payment, $statement);

                $existing = PublisherPaymentSettlement::withoutGlobalScopes()
                    ->where('settlement_reference', $reference)
                    ->first();
                if ($existing) {
                    if ($existing->publisher_payment_id !== $payment->id
                        || (int) $existing->amount_minor !== $amountMinor
                        || $existing->currency !== $payment->currency
                        || $existing->settled_on->toDateString() !== $settledOn->toDateString()) {
                        throw ValidationException::withMessages([
                            'settlement_reference' => 'This settlement reference already exists with different immutable details.',
                        ]);
                    }

                    return $payment;
                }

                if (! in_array($payment->status, [
                    PublisherPaymentStatus::Approved,
                    PublisherPaymentStatus::Scheduled,
                    PublisherPaymentStatus::Processing,
                    PublisherPaymentStatus::PartiallyPaid,
                ], true)) {
                    throw ValidationException::withMessages(['status' => 'This payout is not approved for settlement.']);
                }
                $remaining = $payment->remainingAmountMinor();
                if ($amountMinor <= 0 || $amountMinor > $remaining || $amountMinor > (int) $statement->balance_due_minor) {
                    throw ValidationException::withMessages([
                        'amount_minor' => 'Settlement must be positive and cannot exceed the remaining payout or statement balance.',
                    ]);
                }

                PublisherPaymentSettlement::withoutGlobalScopes()->create([
                    'organization_id' => $payment->organization_id,
                    'publisher_id' => $payment->publisher_id,
                    'publisher_payment_id' => $payment->id,
                    'settlement_reference' => $reference,
                    'amount_minor' => $amountMinor,
                    'currency' => $payment->currency,
                    'settled_on' => $settledOn,
                    'recorded_by' => $actor->id,
                ]);

                $settled = (int) PublisherPaymentSettlement::withoutGlobalScopes()
                    ->where('publisher_payment_id', $payment->id)
                    ->sum('amount_minor');
                $paymentStatus = $settled === (int) $payment->amount_minor
                    ? PublisherPaymentStatus::Paid
                    : PublisherPaymentStatus::PartiallyPaid;
                $payment->update([
                    'settled_amount_minor' => $settled,
                    'status' => $paymentStatus,
                    'horus_payment_reference' => $reference,
                    'paid_at' => $settledOn,
                    'processed_by' => $actor->id,
                ]);

                $paymentIds = PublisherPayment::withoutGlobalScopes()
                    ->where('publisher_statement_id', $statement->id)
                    ->pluck('id');
                $statementPaid = (int) PublisherPaymentSettlement::withoutGlobalScopes()
                    ->whereIn('publisher_payment_id', $paymentIds)
                    ->sum('amount_minor');
                $balance = max(0, (int) $statement->opening_balance_minor + (int) $statement->publisher_earnings_minor - $statementPaid);
                $statement->update([
                    'paid_minor' => $statementPaid,
                    'balance_due_minor' => $balance,
                    'carry_forward_minor' => $balance > 0 ? $balance : 0,
                    'status' => $balance === 0
                        ? PublisherStatementStatus::Paid
                        : PublisherStatementStatus::PartiallyPaid,
                ]);

                $event = $paymentStatus === PublisherPaymentStatus::Paid
                    ? 'finance.payout.settled'
                    : 'finance.payout.partially_settled';
                $this->audit->record($event, $payment->organization_id, $actor, $payment, newValues: [
                    'settlement_reference' => $reference,
                    'settlement_amount_minor' => $amountMinor,
                    'total_settled_minor' => $settled,
                    'statement_balance_minor' => $balance,
                    'currency' => $payment->currency,
                ]);

                return $payment->refresh(['settlements']);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'settlement_reference' => 'This settlement reference has already been recorded.',
            ]);
        }
    }

    public function hold(PublisherPayment $payment, string $reason, User $actor): PublisherPayment
    {
        return $this->stop($payment, PublisherPaymentStatus::Held, $reason, $actor);
    }

    public function fail(PublisherPayment $payment, string $reason, User $actor): PublisherPayment
    {
        return $this->stop($payment, PublisherPaymentStatus::Failed, $reason, $actor);
    }

    public function releaseHold(PublisherPayment $payment, User $actor): PublisherPayment
    {
        $this->authorize($actor, 'finance.payments.settle');

        return DB::transaction(function () use ($payment, $actor): PublisherPayment {
            $payment = PublisherPayment::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== PublisherPaymentStatus::Held) {
                throw ValidationException::withMessages(['status' => 'Only a held payout can be released.']);
            }
            $payment->update([
                'status' => data_get($payment->metadata, 'held_from_status') === PublisherPaymentStatus::Pending->value
                    ? PublisherPaymentStatus::Pending
                    : ((int) $payment->settled_amount_minor > 0
                        ? PublisherPaymentStatus::PartiallyPaid
                        : PublisherPaymentStatus::Approved),
                'failure_reason' => null,
                'publisher_message' => null,
                'held_at' => null,
                'held_by' => null,
                'metadata' => collect((array) $payment->metadata)->except('held_from_status')->all() ?: null,
            ]);
            $this->audit->record('finance.payout.hold_released', $payment->organization_id, $actor, $payment, newValues: [
                'status' => $payment->status->value,
            ]);

            return $payment->refresh();
        });
    }

    public function markPaid(PublisherPayment $payment, string $reference, User $actor, ?int $settledAmountMinor = null): PublisherPayment
    {
        return $this->recordSettlement(
            $payment,
            $reference,
            $settledAmountMinor ?? $payment->remainingAmountMinor(),
            now(),
            $actor,
        );
    }

    private function stop(PublisherPayment $payment, PublisherPaymentStatus $target, string $reason, User $actor): PublisherPayment
    {
        $this->authorize($actor, 'finance.payments.settle');
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A safe operational reason is required.']);
        }

        return DB::transaction(function () use ($payment, $target, $reason, $actor): PublisherPayment {
            $payment = PublisherPayment::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status === $target && $payment->failure_reason === $reason) {
                return $payment;
            }
            if ($payment->status->isTerminal()) {
                throw ValidationException::withMessages(['status' => 'A terminal payout cannot change to this state.']);
            }
            $before = $payment->status;
            $values = [
                'status' => $target,
                'failure_reason' => $reason,
                'publisher_message' => $reason,
            ];
            if ($target === PublisherPaymentStatus::Held) {
                $values += [
                    'held_at' => now(),
                    'held_by' => $actor->id,
                    'metadata' => array_merge((array) $payment->metadata, ['held_from_status' => $before->value]),
                ];
            } else {
                $values += ['failed_at' => now(), 'failed_by' => $actor->id];
            }
            $payment->update($values);
            $this->audit->record(
                $target === PublisherPaymentStatus::Held ? 'finance.payout.held' : 'finance.payout.failed',
                $payment->organization_id,
                $actor,
                $payment,
                ['status' => $before->value],
                ['status' => $target->value, 'reason_recorded' => true, 'settled_amount_minor' => (int) $payment->settled_amount_minor],
            );

            return $payment->refresh();
        });
    }

    private function assertStatementEligible(PublisherStatement $statement): void
    {
        if (! in_array($statement->status, [PublisherStatementStatus::Payable, PublisherStatementStatus::PartiallyPaid], true)) {
            throw ValidationException::withMessages(['statement' => 'Only a payable or partially paid statement is payout eligible.']);
        }
        if ($statement->invoiceRequired() && $statement->publisher_invoice_status !== PublisherInvoiceStatus::Accepted) {
            throw ValidationException::withMessages(['publisher_invoice' => 'The required Publisher invoice must be accepted before payout.']);
        }
    }

    private function verifiedProfile(PublisherStatement $statement, bool $lock): PublisherPaymentProfile
    {
        $query = PublisherPaymentProfile::withoutGlobalScopes()
            ->where('publisher_id', $statement->publisher_id)
            ->where('organization_id', $statement->organization_id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $profile = $query->first();
        if ($profile?->verification_status !== PublisherPaymentProfileStatus::Verified) {
            throw ValidationException::withMessages([
                'payment_profile' => 'The Publisher payment destination must be verified before payout.',
            ]);
        }
        if (strtoupper((string) $profile->currency) !== strtoupper((string) $statement->currency)) {
            throw ValidationException::withMessages([
                'currency' => 'The verified payment profile currency must match the statement currency.',
            ]);
        }

        return $profile;
    }

    private function assertPaymentGraph(PublisherPayment $payment, PublisherStatement $statement): void
    {
        if ($payment->publisher_statement_id !== $statement->id
            || $payment->publisher_id !== $statement->publisher_id
            || $payment->organization_id !== $statement->organization_id
            || strtoupper((string) $payment->currency) !== strtoupper((string) $statement->currency)) {
            throw ValidationException::withMessages(['payment' => 'The payout does not match its Publisher statement and currency.']);
        }
    }

    /** @return array<int, string> */
    private function reservingStatuses(): array
    {
        return array_map(
            fn (PublisherPaymentStatus $status): string => $status->value,
            array_filter(PublisherPaymentStatus::cases(), fn (PublisherPaymentStatus $status): bool => $status->reservesBalance()),
        );
    }

    private function authorize(User $actor, string $permission): void
    {
        if (! $actor->isHorusAdministrator() || ! $actor->hasPermission($permission)) {
            abort(403);
        }
    }
}
