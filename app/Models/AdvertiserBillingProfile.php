<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvertiserBillingProfile extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'advertiser_id', 'legal_name', 'billing_email', 'currency', 'country_code', 'address_line_1', 'address_line_2', 'city', 'region', 'postal_code', 'tax_identifier', 'payment_terms_days', 'is_default', 'status'];

    protected function casts(): array { return ['tax_identifier' => 'encrypted', 'is_default' => 'boolean']; }

    public function advertiser(): BelongsTo { return $this->belongsTo(Advertiser::class); }
}
