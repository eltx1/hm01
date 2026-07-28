<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GamRemoteObject extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'gam_connection_id', 'local_object_type', 'local_object_id',
        'remote_object_type', 'remote_object_id', 'idempotency_key', 'payload_hash',
        'remote_status', 'metadata', 'synced_at',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'synced_at' => 'datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GamConnection::class, 'gam_connection_id');
    }
}
