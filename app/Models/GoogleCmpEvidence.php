<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleCmpEvidence extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'google_cmp_evidence';

    protected $fillable = [
        'organization_id', 'site_id', 'environment', 'cmp_name', 'tcf_cmp_id', 'platform',
        'last_verification_date', 'operator_verification_status', 'verified_by',
    ];

    protected function casts(): array
    {
        return ['tcf_cmp_id' => 'integer', 'last_verification_date' => 'date'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
