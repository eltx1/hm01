<?php

namespace App\Models;

use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class DemandAccount extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $fillable = [
        'public_key', 'organization_id', 'demand_network_id', 'publisher_id',
        'partner_organization_id', 'name', 'scope', 'integration_mode',
        'approval_status', 'is_enabled', 'is_default', 'revenue_share_percent',
        'fallback_priority', 'account_identifier', 'configuration', 'approved_at',
        'last_tested_at', 'last_successful_sync_at', 'rejection_reason',
        'created_by', 'updated_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (DemandAccount $account): void {
            $account->public_key ??= 'dm_'.Str::lower(Str::random(24));
        });
    }

    protected function casts(): array
    {
        return [
            'scope' => DemandAccountScope::class,
            'integration_mode' => DemandIntegrationMode::class,
            'approval_status' => DemandApprovalStatus::class,
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'revenue_share_percent' => 'decimal:3',
            'configuration' => 'array',
            'approved_at' => 'datetime',
            'last_tested_at' => 'datetime',
            'last_successful_sync_at' => 'datetime',
        ];
    }

    public function network(): BelongsTo { return $this->belongsTo(DemandNetwork::class, 'demand_network_id'); }
    public function publisher(): BelongsTo { return $this->belongsTo(Publisher::class); }
    public function partnerOrganization(): BelongsTo { return $this->belongsTo(Organization::class, 'partner_organization_id'); }
    public function credentials(): HasMany { return $this->hasMany(DemandAccountCredential::class); }
    public function sites(): HasMany { return $this->hasMany(DemandSite::class); }
    public function adsTxtRecords(): HasMany { return $this->hasMany(DemandAdsTxtRecord::class); }
    public function reportImports(): HasMany { return $this->hasMany(DemandReportImport::class); }
    public function remoteObjects(): HasMany { return $this->hasMany(DemandRemoteObject::class); }
    public function syncLogs(): HasMany { return $this->hasMany(DemandSyncLog::class); }
    public function errors(): HasMany { return $this->hasMany(DemandError::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function updater(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
}
