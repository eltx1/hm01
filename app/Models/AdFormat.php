<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdFormat extends Model
{
    use HasUlids;

    protected $fillable = ['code', 'display_name', 'placement_type', 'media_type', 'default_sizes', 'capabilities', 'defaults', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['default_sizes' => 'array', 'capabilities' => 'array', 'defaults' => 'array', 'is_active' => 'boolean'];
    }

    public function placements(): HasMany { return $this->hasMany(Placement::class); }
}
