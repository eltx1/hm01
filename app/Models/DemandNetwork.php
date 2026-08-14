<?php

namespace App\Models;

use App\Enums\DemandIntegrationMode;
use App\Enums\DemandNetworkCode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemandNetwork extends Model
{
    use HasUlids;

    protected $fillable = [
        'code', 'name', 'connector_class', 'default_integration_mode', 'is_enabled',
        'supports_direct_js', 'supports_gam_creative', 'supports_gam_line_item',
        'supports_api', 'script_origins', 'capabilities', 'privacy_capabilities', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'code' => DemandNetworkCode::class,
            'default_integration_mode' => DemandIntegrationMode::class,
            'is_enabled' => 'boolean',
            'supports_direct_js' => 'boolean',
            'supports_gam_creative' => 'boolean',
            'supports_gam_line_item' => 'boolean',
            'supports_api' => 'boolean',
            'script_origins' => 'array',
            'capabilities' => 'array',
            'privacy_capabilities' => 'array',
            'metadata' => 'array',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(DemandAccount::class);
    }
}
