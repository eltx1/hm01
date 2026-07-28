<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TagVersion extends Model
{
    use HasUlids;

    protected $fillable = ['organization_id', 'version', 'gpt_url', 'settings', 'checksum', 'is_active', 'published_at'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'is_active' => 'boolean', 'published_at' => 'datetime'];
    }

    public function siteConfigs(): HasMany { return $this->hasMany(SiteConfig::class); }
}
