<?php

namespace App\Models;

use App\Enums\CampaignNetworkStatus;
use App\Enums\GamConnectionType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignNetworkInstance extends Model
{
    use BelongsToOrganization, HasUlids;
    protected $fillable = ['organization_id', 'campaign_id', 'gam_connection_id', 'network_type', 'network_code', 'status', 'budget_allocated_minor', 'site_ids', 'placement_ids', 'deployment_plan', 'planned_objects', 'completed_objects', 'cursor', 'last_error', 'remote_status', 'deployed_at', 'last_synced_at', 'drift_detected_at'];
    protected function casts(): array
    {
        return ['network_type' => GamConnectionType::class, 'status' => CampaignNetworkStatus::class, 'site_ids' => 'array', 'placement_ids' => 'array', 'deployment_plan' => 'array', 'deployed_at' => 'datetime', 'last_synced_at' => 'datetime', 'drift_detected_at' => 'datetime'];
    }
    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
    public function connection(): BelongsTo { return $this->belongsTo(GamConnection::class, 'gam_connection_id'); }
    public function deliveryLogs(): HasMany { return $this->hasMany(CampaignDeliveryLog::class); }
}
