<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PrebidAdapter extends Model
{
    use HasUlids;

    protected $fillable = ['code', 'display_name', 'module_code', 'publisher_parameter', 'placement_parameter', 'required_public_parameters', 'optional_public_parameters', 'supported_media_types', 'supported_sizes', 'documentation_url', 'enabled'];

    protected function casts(): array
    {
        return ['required_public_parameters' => 'array', 'optional_public_parameters' => 'array', 'supported_media_types' => 'array', 'supported_sizes' => 'array', 'enabled' => 'boolean'];
    }
}
