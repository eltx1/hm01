<?php

namespace App\Models;

use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandWidget extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'demand_placement_id', 'name', 'remote_widget_id',
        'widget_code', 'integration_mode', 'direct_tag_template',
        'gam_creative_template', 'approval_status', 'is_enabled',
        'configuration', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'integration_mode' => DemandIntegrationMode::class,
            'approval_status' => DemandApprovalStatus::class,
            'is_enabled' => 'boolean',
            'configuration' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function demandPlacement(): BelongsTo
    {
        return $this->belongsTo(DemandPlacement::class);
    }
}
