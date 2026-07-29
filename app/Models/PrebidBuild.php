<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PrebidBuild extends Model
{
    use HasUlids;

    protected $fillable = ['organization_id', 'name', 'version', 'file_path', 'minified_path', 'checksum', 'modules', 'is_active', 'built_at'];

    protected function casts(): array
    {
        return ['modules' => 'array', 'is_active' => 'boolean', 'built_at' => 'datetime'];
    }
}
