<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GamNetwork extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'gam_connection_id', 'network_code', 'display_name', 'currency_code', 'time_zone', 'is_test', 'is_current', 'capabilities', 'last_seen_at'];

    protected function casts(): array
    {
        return ['is_test' => 'boolean', 'is_current' => 'boolean', 'capabilities' => 'array', 'last_seen_at' => 'datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GamConnection::class, 'gam_connection_id');
    }
}
