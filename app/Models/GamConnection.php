<?php

namespace App\Models;

use App\Enums\GamConnectionType;
use App\Enums\GamCredentialType;
use App\Enums\GamHealthStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class GamConnection extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'type', 'credential_type', 'driver', 'network_code',
        'application_name', 'is_primary', 'is_enabled', 'dry_run_default', 'health_status',
        'last_health_check_at', 'last_successful_sync_at', 'configuration', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => GamConnectionType::class,
            'credential_type' => GamCredentialType::class,
            'health_status' => GamHealthStatus::class,
            'is_primary' => 'boolean',
            'is_enabled' => 'boolean',
            'dry_run_default' => 'boolean',
            'last_health_check_at' => 'datetime',
            'last_successful_sync_at' => 'datetime',
            'configuration' => 'array',
        ];
    }

    public function credential(): HasOne
    {
        return $this->hasOne(GamCredential::class);
    }

    public function networks(): HasMany
    {
        return $this->hasMany(GamNetwork::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(GamConnectionPermission::class);
    }

    public function remoteObjects(): HasMany
    {
        return $this->hasMany(GamRemoteObject::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(GamApiOperation::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(GamSyncRun::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(GamSyncLog::class);
    }

    public function errors(): HasMany
    {
        return $this->hasMany(GamError::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isPrimaryHorus(): bool
    {
        return $this->type === GamConnectionType::HorusGam && $this->is_primary && $this->is_enabled;
    }
}
