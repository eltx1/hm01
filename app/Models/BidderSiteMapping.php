<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BidderSiteMapping extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'bidder_account_id', 'site_id', 'public_parameters', 'enabled', 'sequence'];

    protected function casts(): array
    {
        return ['public_parameters' => 'array', 'enabled' => 'boolean'];
    }

    public function account(): BelongsTo { return $this->belongsTo(BidderAccount::class, 'bidder_account_id'); }
    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function placementMappings(): HasMany { return $this->hasMany(BidderPlacementMapping::class); }
}
