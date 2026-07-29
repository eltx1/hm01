<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandAdsTxtRecord extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'demand_account_id', 'site_id', 'domain',
        'publisher_account_id', 'relationship', 'certification_authority_id',
        'record_hash', 'raw_record', 'status', 'source', 'last_verified_at',
    ];

    protected function casts(): array
    {
        return ['last_verified_at' => 'datetime'];
    }

    public function account(): BelongsTo { return $this->belongsTo(DemandAccount::class, 'demand_account_id'); }
    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
}
