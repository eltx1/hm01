<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThothSetting extends Model
{
    protected $fillable = ['enabled', 'active_provider', 'timeout_seconds', 'max_output_tokens', 'updated_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1], ['enabled' => false, 'active_provider' => config('thoth.default_provider'), 'timeout_seconds' => config('thoth.timeout_seconds'), 'max_output_tokens' => config('thoth.max_output_tokens')]);
    }
}
