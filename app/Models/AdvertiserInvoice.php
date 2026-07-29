<?php

namespace App\Models;

use App\Enums\AdvertiserInvoiceStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvertiserInvoice extends Model
{
    use BelongsToOrganization, HasUlids;
    protected $fillable = ['organization_id', 'advertiser_id', 'advertiser_billing_profile_id', 'campaign_id', 'invoice_number', 'status', 'currency', 'subtotal_minor', 'tax_minor', 'total_minor', 'issued_on', 'due_on', 'paid_at', 'line_items', 'file_path'];
    protected function casts(): array { return ['status' => AdvertiserInvoiceStatus::class, 'issued_on' => 'date', 'due_on' => 'date', 'paid_at' => 'datetime', 'line_items' => 'array']; }
    public function advertiser(): BelongsTo { return $this->belongsTo(Advertiser::class); }
    public function billingProfile(): BelongsTo { return $this->belongsTo(AdvertiserBillingProfile::class, 'advertiser_billing_profile_id'); }
    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
}
