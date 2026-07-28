<?php

namespace App\Models;

use App\Enums\PlacementDevice;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlacementSize extends Model
{
    use HasUlids;

    protected $fillable = [
        'placement_id', 'size_type', 'width', 'height', 'device', 'min_viewport_width',
        'min_viewport_height', 'max_viewport_width', 'max_viewport_height', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'device' => PlacementDevice::class,
            'width' => 'integer', 'height' => 'integer',
            'min_viewport_width' => 'integer', 'min_viewport_height' => 'integer',
            'max_viewport_width' => 'integer', 'max_viewport_height' => 'integer',
            'priority' => 'integer', 'is_active' => 'boolean',
        ];
    }

    public function placement(): BelongsTo { return $this->belongsTo(Placement::class); }
}
