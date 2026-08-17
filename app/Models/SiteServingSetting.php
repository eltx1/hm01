<?php

namespace App\Models;

use App\Enums\AdsTxtDeploymentMode;
use App\Enums\PrebidConfiguredMode;
use App\Enums\ServingMode;
use App\Enums\SiteManagementRole;
use App\Enums\TrafficGateSitePolicy;
use App\Enums\TrafficGateSiteState;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteServingSetting extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'site_id', 'serving_mode', 'revenue_share_percent', 'prebid_enabled',
        'prebid_configured_mode', 'native_demand_enabled', 'traffic_gate_state', 'traffic_gate_policy',
        'monetization_manager_role', 'monetization_manager_domain', 'monetization_manager_relationship', 'monetization_manager_country',
        'ads_txt_deployment_mode', 'ads_txt_redirect_target', 'ads_txt_redirect_status',
        'ads_txt_redirect_verified_at', 'placement_plan', 'configuration_version',
    ];

    protected function casts(): array
    {
        return [
            'serving_mode' => ServingMode::class,
            'revenue_share_percent' => 'decimal:2',
            'prebid_enabled' => 'boolean',
            'prebid_configured_mode' => PrebidConfiguredMode::class,
            'native_demand_enabled' => 'boolean',
            'traffic_gate_state' => TrafficGateSiteState::class,
            'traffic_gate_policy' => TrafficGateSitePolicy::class,
            'monetization_manager_role' => SiteManagementRole::class,
            'ads_txt_deployment_mode' => AdsTxtDeploymentMode::class,
            'ads_txt_redirect_verified_at' => 'datetime',
            'placement_plan' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
