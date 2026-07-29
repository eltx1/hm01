<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PrebidPriceBucket extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'gam_connection_id', 'code', 'minimum', 'maximum', 'increment', 'precision', 'enabled', 'sort_order'];

    protected function casts(): array
    {
        return ['minimum' => 'decimal:4', 'maximum' => 'decimal:4', 'increment' => 'decimal:4', 'enabled' => 'boolean'];
    }
}
