<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Advertiser extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $fillable = ['organization_id', 'public_key', 'legal_name', 'display_name', 'status', 'billing_email', 'logo_path', 'dashboard_title', 'primary_color', 'internal_notes', 'reviewed_at', 'reviewed_by', 'review_notes'];

    protected static function booted(): void
    {
        static::creating(fn (Advertiser $advertiser) => $advertiser->public_key ??= 'ha_'.Str::lower(Str::random(24)));
    }

    protected $hidden = ['internal_notes', 'review_notes'];

    protected function casts(): array
    {
        return ['status' => AccountStatus::class, 'reviewed_at' => 'datetime'];
    }

    public function contacts(): HasMany { return $this->hasMany(AdvertiserContact::class); }
    public function users(): HasMany { return $this->hasMany(AdvertiserUser::class); }
    public function billingProfiles(): HasMany { return $this->hasMany(AdvertiserBillingProfile::class); }
    public function campaigns(): HasMany { return $this->hasMany(Campaign::class); }
    public function invoices(): HasMany { return $this->hasMany(AdvertiserInvoice::class); }
}
