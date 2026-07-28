<?php

namespace App\Models;

use App\Enums\PrebidBuildStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrebidBuild extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'name', 'version', 'prebid_version', 'source_repository',
        'source_reference', 'modules', 'source_path', 'asset_path', 'minified_path',
        'manifest_path', 'checksum', 'status', 'is_active', 'notes', 'built_at',
        'published_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'status' => PrebidBuildStatus::class,
            'is_active' => 'boolean',
            'built_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function settings(): HasMany
    {
        return $this->hasMany(PrebidSetting::class);
    }
}
