<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class PublisherQualityDecision extends Model
{
    use HasUlids;

    protected $fillable = ['publisher_id', 'review_run_id', 'decision', 'reason', 'previous_status', 'new_status', 'decided_by'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Quality decisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Quality decisions are immutable.'));
    }
}
