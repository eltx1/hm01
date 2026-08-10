<?php

namespace App\Models;

use App\Enums\PublisherPaymentStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublisherPayment extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'publisher_id', 'publisher_statement_id', 'payment_number',
        'status', 'currency', 'amount_minor', 'settled_amount_minor', 'payment_method',
        'horus_payment_reference', 'scheduled_on', 'paid_at', 'notes',
        'publisher_message', 'metadata', 'created_by', 'approved_by', 'approved_at',
        'processed_by', 'idempotency_key', 'failure_reason', 'failed_at',
        'failed_by', 'held_at', 'held_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PublisherPaymentStatus::class, 'scheduled_on' => 'date',
            'paid_at' => 'datetime', 'approved_at' => 'datetime', 'failed_at' => 'datetime',
            'held_at' => 'datetime', 'metadata' => 'array',
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(PublisherStatement::class, 'publisher_statement_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(PublisherPaymentSettlement::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function remainingAmountMinor(): int
    {
        return max(0, (int) $this->amount_minor - (int) $this->settled_amount_minor);
    }
}
