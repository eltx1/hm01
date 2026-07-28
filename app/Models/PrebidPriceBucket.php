<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrebidPriceBucket extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'name', 'code', 'currency_code', 'granularity', 'ranges',
        'is_default', 'enabled', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['ranges' => 'array', 'is_default' => 'boolean', 'enabled' => 'boolean'];
    }

    public function settings(): HasMany { return $this->hasMany(PrebidSetting::class); }
    public function templates(): HasMany { return $this->hasMany(PrebidGamTemplate::class); }
}
