<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdUnit extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id', 'site_id', 'name', 'code', 'description', 'is_enabled',
        'sync_status', 'last_sync_hash', 'last_synced_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'last_synced_at' => 'datetime'];
    }

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function sizes(): HasMany { return $this->hasMany(AdUnitSize::class); }
    public function placements(): HasMany { return $this->hasMany(Placement::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function updater(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
}
