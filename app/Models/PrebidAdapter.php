<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrebidAdapter extends Model
{
    use HasUlids;

    protected $fillable = [
        'bidder_code', 'module_name', 'display_name', 'required_public_parameters',
        'optional_public_parameters', 'supported_media_types', 'supported_sizes',
        'documentation_url', 'is_enabled', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'required_public_parameters' => 'array',
            'optional_public_parameters' => 'array',
            'supported_media_types' => 'array',
            'supported_sizes' => 'array',
            'is_enabled' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function bidders(): HasMany
    {
        return $this->hasMany(PrebidBidder::class);
    }
}
