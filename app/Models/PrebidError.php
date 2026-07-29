<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PrebidError extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'gam_connection_id', 'site_id', 'prebid_setup_run_id', 'category', 'code', 'message', 'context', 'resolved_at'];

    protected function casts(): array
    {
        return ['context' => 'array', 'resolved_at' => 'datetime'];
    }
}
