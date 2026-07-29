<?php

namespace App\Models;

use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\DemandSyncStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemandSite extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'demand_account_id', 'site_id', 'approval_status',
        'is_enabled', 'is_default', 'integration_mode', 'revenue_share_percent',
        'fallback_priority', 'remote_site_id', 'configuration', 'sync_status',
        'last_synced_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'approval_status' => DemandApprovalStatus::class,
            'integration_mode' => DemandIntegrationMode::class,
            'sync_status' => DemandSyncStatus::class,
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'revenue_share_percent' => 'decimal:3',
            'configuration' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo { return $this->belongsTo(DemandAccount::class, 'demand_account_id'); }
    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function placements(): HasMany { return $this->hasMany(DemandPlacement::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function updater(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
}
