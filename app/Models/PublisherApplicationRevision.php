<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PublisherApplicationRevision extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['publisher_application_id', 'publisher_quality_profile_id', 'submitted_by', 'version', 'snapshot', 'snapshot_hash', 'submitted_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'submitted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Submitted Publisher application revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Submitted Publisher application revisions are immutable.'));
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(PublisherApplication::class, 'publisher_application_id');
    }

    public function qualityProfile(): BelongsTo
    {
        return $this->belongsTo(PublisherQualityProfile::class, 'publisher_quality_profile_id');
    }
}
