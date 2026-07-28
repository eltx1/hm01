<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrebidSetting extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'site_id', 'gam_connection_id', 'prebid_build_id',
        'prebid_price_bucket_id', 'enabled', 'auction_timeout_ms', 'price_granularity',
        'currency_code', 'bidder_sequence', 'consent_behavior', 'lazy_loading',
        'refresh_behavior', 'timeout_reporting', 'gam_fallback', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'auction_timeout_ms' => 'integer', 'consent_behavior' => 'array',
            'lazy_loading' => 'array', 'refresh_behavior' => 'array', 'timeout_reporting' => 'boolean',
            'gam_fallback' => 'boolean',
        ];
    }

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function connection(): BelongsTo { return $this->belongsTo(GamConnection::class, 'gam_connection_id'); }
    public function build(): BelongsTo { return $this->belongsTo(PrebidBuild::class, 'prebid_build_id'); }
    public function priceBucket(): BelongsTo { return $this->belongsTo(PrebidPriceBucket::class, 'prebid_price_bucket_id'); }
}
