<?php

namespace App\Models;

use App\Enums\PrebidConfiguredMode;
use App\Enums\ServingMode;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteServingSetting extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'site_id', 'serving_mode', 'revenue_share_percent', 'prebid_enabled', 'prebid_configured_mode', 'native_demand_enabled', 'placement_plan', 'configuration_version'];

    protected function casts(): array
    {
        return [
            'serving_mode' => ServingMode::class,
            'revenue_share_percent' => 'decimal:2',
            'prebid_enabled' => 'boolean',
            'prebid_configured_mode' => PrebidConfiguredMode::class,
            'native_demand_enabled' => 'boolean',
            'placement_plan' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
