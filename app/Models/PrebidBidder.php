<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PrebidBidder extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id', 'prebid_adapter_id', 'name', 'code', 'enabled',
        'default_public_parameters', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'default_public_parameters' => 'array'];
    }

    public function adapter(): BelongsTo { return $this->belongsTo(PrebidAdapter::class, 'prebid_adapter_id'); }
    public function accounts(): HasMany { return $this->hasMany(BidderAccount::class); }
}
