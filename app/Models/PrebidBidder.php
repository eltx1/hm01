<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrebidBidder extends Model
{
    use HasUlids;

    protected $fillable = ['organization_id', 'prebid_adapter_id', 'code', 'display_name', 'default_public_parameters', 'enabled', 'sort_order'];

    protected function casts(): array
    {
        return ['default_public_parameters' => 'array', 'enabled' => 'boolean'];
    }

    public function adapter(): BelongsTo { return $this->belongsTo(PrebidAdapter::class, 'prebid_adapter_id'); }
    public function accounts(): HasMany { return $this->hasMany(BidderAccount::class); }
}
