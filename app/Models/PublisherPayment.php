<?php

namespace App\Models;

use App\Enums\PublisherPaymentStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublisherPayment extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'publisher_id', 'publisher_statement_id', 'payment_number',
        'status', 'currency', 'amount_minor', 'payment_method', 'horus_payment_reference',
        'scheduled_on', 'paid_at', 'notes', 'metadata', 'created_by', 'approved_by', 'processed_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PublisherPaymentStatus::class, 'scheduled_on' => 'date',
            'paid_at' => 'datetime', 'metadata' => 'array',
        ];
    }

    public function publisher(): BelongsTo { return $this->belongsTo(Publisher::class); }
    public function statement(): BelongsTo { return $this->belongsTo(PublisherStatement::class, 'publisher_statement_id'); }
}
