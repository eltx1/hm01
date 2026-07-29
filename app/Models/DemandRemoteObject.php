<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandRemoteObject extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'demand_account_id', 'gam_connection_id',
        'connection_key', 'local_object_type', 'local_object_id',
        'remote_object_type', 'remote_object_id', 'idempotency_key',
        'payload_hash', 'remote_status', 'metadata', 'synced_at',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'synced_at' => 'datetime'];
    }

    public function account(): BelongsTo { return $this->belongsTo(DemandAccount::class, 'demand_account_id'); }
    public function gamConnection(): BelongsTo { return $this->belongsTo(GamConnection::class); }
}
