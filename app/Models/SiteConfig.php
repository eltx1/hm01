<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteConfig extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'site_id', 'loader_release_id', 'tag_version_id', 'status',
        'immediate_pause', 'debug_enabled', 'house_ad_testing', 'single_request_mode',
        'cache_ttl_seconds', 'preview_version', 'test_version', 'production_version', 'page_targeting',
        'privacy_settings', 'gpt_settings', 'supply_chain_settings', 'observability_settings',
    ];

    protected function casts(): array
    {
        return [
            'immediate_pause' => 'boolean', 'debug_enabled' => 'boolean',
            'house_ad_testing' => 'boolean', 'single_request_mode' => 'boolean',
            'cache_ttl_seconds' => 'integer', 'page_targeting' => 'array',
            'privacy_settings' => 'array', 'gpt_settings' => 'array',
            'supply_chain_settings' => 'array', 'observability_settings' => 'array',
        ];
    }

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function loaderRelease(): BelongsTo { return $this->belongsTo(LoaderRelease::class); }
    public function tagVersion(): BelongsTo { return $this->belongsTo(TagVersion::class); }
    public function versions(): HasMany { return $this->hasMany(ConfigVersion::class); }
}
