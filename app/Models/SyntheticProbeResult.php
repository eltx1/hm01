<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SyntheticProbeResult extends Model
{
    use BelongsToOrganization, HasUlids;
    protected $fillable = ['organization_id', 'site_id', 'probe', 'environment', 'status', 'latency_ms', 'checks', 'release', 'observed_at'];
    protected function casts(): array { return ['checks' => 'array', 'observed_at' => 'datetime']; }
}
