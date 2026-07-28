<?php

namespace App\Models;

use App\Enums\PrebidBidderSequence;
use App\Enums\PrebidPriceGranularity;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrebidSetting extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'site_id', 'prebid_build_id', 'is_enabled', 'auction_timeout_ms',
        'price_granularity', 'currency', 'bidder_sequence', 'consent_config', 'user_sync_config',
        'lazy_loading_enabled', 'refresh_enabled', 'refresh_interval_seconds',
        'timeout_reporting_enabled', 'gam_fallback_enabled', 'send_all_bids', 'debug_enabled',
        'configuration_version', 'advanced_config',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'price_granularity' => PrebidPriceGranularity::class,
            'bidder_sequence' => PrebidBidderSequence::class,
            'consent_config' => 'array',
            'user_sync_config' => 'array',
            'lazy_loading_enabled' => 'boolean',
            'refresh_enabled' => 'boolean',
            'timeout_reporting_enabled' => 'boolean',
            'gam_fallback_enabled' => 'boolean',
            'send_all_bids' => 'boolean',
            'debug_enabled' => 'boolean',
            'advanced_config' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function build(): BelongsTo
    {
        return $this->belongsTo(PrebidBuild::class, 'prebid_build_id');
    }

    public function priceBuckets(): HasMany
    {
        return $this->hasMany(PrebidPriceBucket::class);
    }
}
