<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BidderPlacementMapping extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'bidder_site_mapping_id', 'placement_id', 'placement_id_value',
        'public_parameters', 'is_enabled', 'sequence',
    ];

    protected function casts(): array
    {
        return ['public_parameters' => 'array', 'is_enabled' => 'boolean'];
    }

    public function siteMapping(): BelongsTo
    {
        return $this->belongsTo(BidderSiteMapping::class, 'bidder_site_mapping_id');
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(Placement::class);
    }
}
