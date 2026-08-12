<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PrebidSetting extends Model
{
    use BelongsToOrganization, HasUlids;

    public const SCOPE_GAM_CONNECTION = 'GAM_CONNECTION';
    public const SCOPE_SITE_STANDALONE = 'SITE_STANDALONE';

    protected $fillable = [
        'organization_id', 'scope', 'gam_connection_id', 'site_id', 'prebid_build_id', 'enabled',
        'auction_timeout_ms', 'price_granularity', 'currency', 'bidder_sequence', 'consent_behavior',
        'lazy_loading', 'refresh_behavior', 'bidder_timeout_reporting', 'gam_fallback', 'configuration', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'consent_behavior' => 'array', 'lazy_loading' => 'array',
            'refresh_behavior' => 'array', 'bidder_timeout_reporting' => 'boolean',
            'gam_fallback' => 'boolean', 'configuration' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PrebidSetting $settings): void {
            $gamOwned = $settings->scope === self::SCOPE_GAM_CONNECTION
                && filled($settings->gam_connection_id)
                && blank($settings->site_id);
            $siteOwned = $settings->scope === self::SCOPE_SITE_STANDALONE
                && filled($settings->site_id)
                && blank($settings->gam_connection_id);

            if (! $gamOwned && ! $siteOwned) {
                throw new LogicException('A Prebid setting must have exactly one valid runtime owner.');
            }
        });
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GamConnection::class, 'gam_connection_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function build(): BelongsTo
    {
        return $this->belongsTo(PrebidBuild::class, 'prebid_build_id');
    }
}
