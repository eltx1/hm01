<?php

namespace App\Models;

use App\Enums\PlacementStatus;
use App\Enums\PlacementType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Placement extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id', 'site_id', 'ad_unit_id', 'name', 'code', 'type', 'status',
        'lazy_load_enabled', 'lazy_fetch_margin_percent', 'lazy_render_margin_percent',
        'lazy_mobile_scaling', 'refresh_enabled', 'refresh_interval_seconds', 'refresh_limit',
        'collapse_empty_div', 'safeframe_enabled', 'sort_order', 'metadata', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => PlacementType::class,
            'status' => PlacementStatus::class,
            'lazy_load_enabled' => 'boolean',
            'lazy_mobile_scaling' => 'decimal:2',
            'refresh_enabled' => 'boolean',
            'collapse_empty_div' => 'boolean',
            'safeframe_enabled' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function adUnit(): BelongsTo { return $this->belongsTo(AdUnit::class); }
    public function sizes(): HasMany { return $this->hasMany(PlacementSize::class)->orderBy('priority'); }
    public function targeting(): HasMany { return $this->hasMany(PlacementTargeting::class); }
    public function bidderMappings(): HasMany { return $this->hasMany(BidderPlacementMapping::class); }

    public function installationCode(): string
    {
        return '<div class="hm-ad" data-placement="'.$this->code.'"></div>';
    }
}
