<?php

namespace App\Models;

use App\Enums\VerificationMethod;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteVerification extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'site_id', 'site_domain_id', 'method', 'status', 'expected_value', 'evidence', 'failure_reason', 'verified_by', 'attempted_at', 'verified_at'];

    protected function casts(): array
    {
        return ['method' => VerificationMethod::class, 'evidence' => 'array', 'attempted_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(SiteDomain::class, 'site_domain_id');
    }
}
