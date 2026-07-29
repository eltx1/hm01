<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrebidSetting extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'gam_connection_id', 'prebid_build_id', 'enabled', 'auction_timeout_ms', 'price_granularity', 'currency', 'bidder_sequence', 'consent_behavior', 'lazy_loading', 'refresh_behavior', 'bidder_timeout_reporting', 'gam_fallback', 'configuration', 'updated_by'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'consent_behavior' => 'array', 'lazy_loading' => 'array',
            'refresh_behavior' => 'array', 'bidder_timeout_reporting' => 'boolean',
            'gam_fallback' => 'boolean', 'configuration' => 'array',
        ];
    }

    public function connection(): BelongsTo { return $this->belongsTo(GamConnection::class, 'gam_connection_id'); }
    public function build(): BelongsTo { return $this->belongsTo(PrebidBuild::class, 'prebid_build_id'); }
}
