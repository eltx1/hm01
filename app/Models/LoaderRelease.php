<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoaderRelease extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id', 'version', 'source_path', 'minified_path', 'checksum',
        'is_active', 'notes', 'published_at',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'published_at' => 'datetime'];
    }

    public function siteConfigs(): HasMany { return $this->hasMany(SiteConfig::class); }
}
