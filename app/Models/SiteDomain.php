<?php

namespace App\Models;

use App\Enums\VerificationMethod;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteDomain extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'site_id', 'domain', 'is_primary', 'verification_status', 'verification_method', 'verification_token', 'verified_at'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'verification_method' => VerificationMethod::class, 'verified_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(SiteVerification::class);
    }
}
