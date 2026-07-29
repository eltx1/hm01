<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PrebidSetupRun extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'gam_connection_id', 'status', 'dry_run', 'confirmed_by', 'estimated_objects', 'completed_objects', 'cursor', 'planned_objects', 'error_message', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['dry_run' => 'boolean', 'planned_objects' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
