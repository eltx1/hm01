<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplyChainCheck extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'site_id', 'check_type', 'status', 'url', 'http_status',
        'checksum', 'required_checksum', 'snapshot_hash', 'response_body', 'response_bytes',
        'duration_ms', 'content_type', 'trigger', 'initiated_by', 'final_url', 'findings',
        'checked_at', 'first_checked_at', 'occurrence_count',
    ];

    protected function casts(): array
    {
        return ['findings' => 'array', 'checked_at' => 'datetime', 'first_checked_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
