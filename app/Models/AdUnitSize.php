<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdUnitSize extends Model
{
    use HasUlids;

    protected $fillable = ['ad_unit_id', 'size_type', 'width', 'height', 'label', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'width' => 'integer', 'height' => 'integer'];
    }

    public function adUnit(): BelongsTo { return $this->belongsTo(AdUnit::class); }
}
