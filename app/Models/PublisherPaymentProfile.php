<?php

namespace App\Models;

use App\Enums\PublisherPaymentProfileStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublisherPaymentProfile extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'publisher_id', 'beneficiary_name', 'payment_method',
        'currency', 'country', 'billing_address', 'payment_details',
        'account_last_four', 'tax_identifier', 'is_verified',
        'verification_status', 'verification_requested_at', 'verified_at',
        'verified_by', 'verification_reason',
    ];

    protected $hidden = ['payment_details', 'tax_identifier'];

    protected function casts(): array
    {
        return [
            'payment_details' => 'encrypted:array',
            'tax_identifier' => 'encrypted',
            'is_verified' => 'boolean',
            'verification_status' => PublisherPaymentProfileStatus::class,
            'verification_requested_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function maskedAccountReference(): ?string
    {
        return $this->account_last_four ? '••••'.$this->account_last_four : null;
    }
}
