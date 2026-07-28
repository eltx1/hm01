<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrebidBuild extends Model
{
    use HasUlids;

    protected $fillable = [
        'version', 'name', 'source_ref', 'source_commit', 'source_url', 'asset_path',
        'minified_path', 'manifest_path', 'checksum', 'modules', 'adapters', 'status',
        'is_active', 'built_at',
    ];

    protected function casts(): array
    {
        return ['modules' => 'array', 'adapters' => 'array', 'is_active' => 'boolean', 'built_at' => 'datetime'];
    }

    public function settings(): HasMany { return $this->hasMany(PrebidSetting::class); }
}
