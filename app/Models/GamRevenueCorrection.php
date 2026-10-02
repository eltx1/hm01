<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class GamRevenueCorrection extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['context' => 'array', 'snapshot' => 'array', 'job' => 'array', 'proposal' => 'array',
            'receipt' => 'array', 'expires_at' => 'immutable_datetime', 'applied_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $candidate): void {
            if (in_array($candidate->getRawOriginal('status'), ['APPLIED', 'SUPERSEDED'], true)
                || $candidate->isDirty(['actor_id', 'organization_id', 'site_id', 'source_connection_id',
                    'period_start', 'period_end', 'context', 'snapshot', 'query_hash', 'expires_at'])
                || (in_array($candidate->getRawOriginal('status'), ['READY', 'BLOCKED', 'FAILED'], true)
                    && $candidate->isDirty(['proposal', 'digest', 'job']))) {
                throw new \LogicException('Correction evidence is immutable.');
            }
        });
        static::deleting(fn (): never => throw new \LogicException('Correction evidence must be retained.'));
    }
}
