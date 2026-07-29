<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportDimension extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'publisher_id', 'site_id', 'placement_id', 'gam_connection_id',
        'demand_network_id', 'bidder_id', 'advertiser_id', 'campaign_id', 'country_code',
        'device', 'browser', 'operating_system', 'ad_size', 'external_dimensions', 'dimension_hash',
    ];

    protected function casts(): array
    {
        return ['external_dimensions' => 'array'];
    }

    public function publisher(): BelongsTo { return $this->belongsTo(Publisher::class); }
    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function placement(): BelongsTo { return $this->belongsTo(Placement::class); }
    public function gamConnection(): BelongsTo { return $this->belongsTo(GamConnection::class); }
    public function demandNetwork(): BelongsTo { return $this->belongsTo(DemandNetwork::class); }
    public function advertiser(): BelongsTo { return $this->belongsTo(Advertiser::class); }
    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
}
