<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrebidBidder extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'prebid_adapter_id', 'code', 'display_name', 'alias_of', 'is_enabled', 'defaults'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'defaults' => 'array'];
    }

    public function adapter(): BelongsTo
    {
        return $this->belongsTo(PrebidAdapter::class, 'prebid_adapter_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(BidderAccount::class);
    }
}
