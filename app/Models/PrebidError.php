<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrebidError extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'gam_connection_id', 'prebid_setup_run_id', 'category', 'code',
        'message', 'retryable', 'context', 'occurred_at', 'resolved_at', 'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'retryable' => 'boolean', 'context' => 'array', 'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo { return $this->belongsTo(GamConnection::class, 'gam_connection_id'); }
    public function run(): BelongsTo { return $this->belongsTo(PrebidSetupRun::class, 'prebid_setup_run_id'); }
}
