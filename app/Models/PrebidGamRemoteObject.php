<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrebidGamRemoteObject extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'gam_connection_id', 'prebid_setup_run_id', 'object_key',
        'object_type', 'remote_object_id', 'payload_hash', 'remote_status', 'metadata', 'synced_at',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'synced_at' => 'datetime'];
    }

    public function connection(): BelongsTo { return $this->belongsTo(GamConnection::class, 'gam_connection_id'); }
    public function run(): BelongsTo { return $this->belongsTo(PrebidSetupRun::class, 'prebid_setup_run_id'); }
}
