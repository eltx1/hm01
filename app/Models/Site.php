<?php

namespace App\Models;

use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Site extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $fillable = ['organization_id', 'publisher_id', 'public_key', 'display_name', 'primary_domain', 'language', 'content_category', 'country', 'main_traffic_countries', 'estimated_monthly_pageviews', 'estimated_monthly_users', 'current_monetization_providers', 'current_gam_network_code', 'current_adsense_status', 'current_adx_status', 'prebid_enabled', 'native_demand_enabled', 'default_revenue_share_percent', 'serving_mode', 'gam_connection_id', 'status', 'submitted_at', 'approved_at'];

    protected static function booted(): void
    {
        static::creating(function (Site $site): void {
            $site->public_key ??= 'hm_'.Str::lower(Str::random(24));
            $site->serving_mode ??= ServingMode::HorusGam;
            $site->status ??= SiteStatus::Draft;
        });
    }

    protected function casts(): array
    {
        return [
            'main_traffic_countries' => 'array', 'current_monetization_providers' => 'array',
            'prebid_enabled' => 'boolean', 'native_demand_enabled' => 'boolean',
            'default_revenue_share_percent' => 'decimal:2', 'serving_mode' => ServingMode::class,
            'status' => SiteStatus::class, 'submitted_at' => 'datetime', 'approved_at' => 'datetime',
        ];
    }

    protected function primaryDomain(): Attribute
    {
        return Attribute::make(set: fn (string $value) => strtolower(rtrim(preg_replace('#^https?://#i', '', trim($value)), '/')));
    }

    public function publisher(): BelongsTo { return $this->belongsTo(Publisher::class); }
    public function gamConnection(): BelongsTo { return $this->belongsTo(GamConnection::class, 'gam_connection_id'); }
    public function domains(): HasMany { return $this->hasMany(SiteDomain::class); }
    public function verifications(): HasMany { return $this->hasMany(SiteVerification::class); }
    public function reviews(): HasMany { return $this->hasMany(SiteReview::class); }
    public function notes(): HasMany { return $this->hasMany(SiteNote::class); }
    public function statusHistory(): HasMany { return $this->hasMany(SiteStatusHistory::class); }
    public function servingSettings(): HasOne { return $this->hasOne(SiteServingSetting::class); }
    public function servingModeChanges(): HasMany { return $this->hasMany(ServingModeChange::class); }
    public function adUnits(): HasMany { return $this->hasMany(AdUnit::class); }
    public function placements(): HasMany { return $this->hasMany(Placement::class); }
    public function targeting(): HasMany { return $this->hasMany(PlacementTargeting::class); }
    public function layoutProfiles(): HasMany { return $this->hasMany(SiteLayoutProfile::class); }
    public function siteConfig(): HasOne { return $this->hasOne(SiteConfig::class); }
    public function configVersions(): HasMany { return $this->hasMany(ConfigVersion::class); }

    public function installationCode(): string
    {
        return '<script async src="'.config('horus.loader_url').'" data-site-key="'.$this->public_key.'"></script>';
    }
}
