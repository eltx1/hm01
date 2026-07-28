<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublisherPaymentProfile extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'publisher_id', 'beneficiary_name', 'payment_method', 'currency', 'country', 'billing_address', 'payment_details', 'account_last_four', 'tax_identifier', 'is_verified'];

    protected $hidden = ['payment_details', 'tax_identifier'];

    protected function casts(): array
    {
        return ['payment_details' => 'encrypted:array', 'tax_identifier' => 'encrypted', 'is_verified' => 'boolean'];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }
}
