<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublisherPaymentSettlement extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'publisher_id', 'publisher_payment_id',
        'settlement_reference', 'amount_minor', 'currency', 'settled_on',
        'recorded_by', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'settled_on' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Settlement records are immutable.'));
        static::deleting(fn (): never => throw new \LogicException('Settlement records are immutable.'));
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PublisherPayment::class, 'publisher_payment_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
