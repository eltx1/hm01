<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MobileApp extends Model
{
    use BelongsToOrganization, HasUlids;
    protected $guarded = [];
    protected static function booted(): void
    {
        static::updating(function (MobileApp $app) {
            if ($app->isDirty(['organization_id', 'publisher_id', 'public_id', 'platform', 'store_app_id', 'store', 'store_url'])) {
                throw new \LogicException('App ownership and store identity are permanent. Register a different listing separately.');
            }
        });
    }
    protected function casts(): array { return ['app_ads_evidence' => 'array', 'store_reviewed_at' => 'datetime']; }
    public function publisher() { return $this->belongsTo(Publisher::class); }
    public function gamConnection() { return $this->belongsTo(GamConnection::class); }
    public function seller() { return $this->hasOne(SellerDeclaration::class); }
    public function adUnits() { return $this->hasMany(MobileAppAdUnit::class); }
    public function reportBindings() { return $this->hasMany(MobileAppReportBinding::class); }
}
