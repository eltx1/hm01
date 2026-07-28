<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrebidPriceBucket extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'prebid_setting_id', 'label', 'minimum', 'maximum',
        'increment', 'precision', 'priority', 'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'minimum' => 'decimal:4',
            'maximum' => 'decimal:4',
            'increment' => 'decimal:4',
            'is_enabled' => 'boolean',
        ];
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(PrebidSetting::class, 'prebid_setting_id');
    }
}
