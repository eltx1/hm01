<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BidderAccount extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'prebid_bidder_id', 'name', 'publisher_id', 'public_parameters', 'enabled', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['public_parameters' => 'array', 'enabled' => 'boolean'];
    }

    public function bidder(): BelongsTo { return $this->belongsTo(PrebidBidder::class, 'prebid_bidder_id'); }
    public function siteMappings(): HasMany { return $this->hasMany(BidderSiteMapping::class); }
}
