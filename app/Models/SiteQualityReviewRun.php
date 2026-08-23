<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class SiteQualityReviewRun extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id', 'site_id', 'publisher_id', 'requested_by', 'trigger', 'status',
        'provider', 'model', 'provider_request_id', 'policy_version', 'schema_version',
        'evidence_snapshot', 'evidence_hash', 'result', 'usage', 'response_hash', 'latency_ms',
        'error_code', 'error_message', 'active_dedupe_key', 'started_at', 'completed_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence_snapshot' => 'array',
            'result' => 'array',
            'usage' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $run): void {
            if (in_array($run->getOriginal('status'), ['COMPLETED', 'FAILED'], true)) {
                throw new LogicException('Completed site quality review runs are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Site quality review runs are immutable.'));
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
