<?php

namespace App\Models;

use App\Enums\ConfigEnvironment;
use App\Enums\ConfigVersionStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ConfigVersion extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'site_id', 'site_config_id', 'source_version_id', 'environment',
        'version', 'status', 'payload', 'checksum', 'file_path', 'created_by',
        'published_at', 'rolled_back_at',
    ];

    protected function casts(): array
    {
        return [
            'environment' => ConfigEnvironment::class,
            'status' => ConfigVersionStatus::class,
            'payload' => 'array',
            'version' => 'integer',
            'published_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function siteConfig(): BelongsTo { return $this->belongsTo(SiteConfig::class); }
    public function sourceVersion(): BelongsTo { return $this->belongsTo(ConfigVersion::class, 'source_version_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function deliveryItem(): HasOne { return $this->hasOne(StaticDeliveryItem::class); }
}
