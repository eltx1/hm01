<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Publisher extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $fillable = ['organization_id', 'legal_name', 'display_name', 'status', 'billing_email', 'logo_path', 'dashboard_title', 'primary_color', 'internal_notes', 'onboarding_step', 'onboarding_submitted_at'];

    protected $hidden = ['internal_notes'];

    protected function casts(): array
    {
        return ['status' => AccountStatus::class, 'onboarding_submitted_at' => 'datetime'];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(PublisherContact::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(PublisherContract::class);
    }

    public function paymentProfile(): HasOne
    {
        return $this->hasOne(PublisherPaymentProfile::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
