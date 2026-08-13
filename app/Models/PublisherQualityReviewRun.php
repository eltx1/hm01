<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PublisherQualityReviewRun extends Model
{
    use HasUlids;

    protected $fillable = ['publisher_id', 'profile_id', 'requested_by', 'status', 'provider', 'model', 'provider_request_id', 'policy_version', 'schema_version', 'evidence_snapshot', 'evidence_hash', 'result', 'usage', 'response_hash', 'latency_ms', 'error_code', 'active_dedupe_key', 'started_at', 'completed_at', 'failed_at'];

    protected function casts(): array
    {
        return ['evidence_snapshot' => 'array', 'result' => 'array', 'usage' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $run): void {
            if (in_array($run->getOriginal('status'), ['COMPLETED', 'FAILED'], true)) {
                throw new LogicException('Completed quality review runs are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Quality review runs are immutable.'));
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PublisherQualityProfile::class, 'profile_id');
    }
}
