<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PublisherApplicationMarketingConsent extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'publisher_application_id', 'user_id', 'opted_in', 'recorded_at',
        'request_evidence_hash', 'evidence_hash',
    ];

    protected function casts(): array
    {
        return ['opted_in' => 'boolean', 'recorded_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Marketing consent evidence is append-only.'));
        static::deleting(fn () => throw new LogicException('Marketing consent evidence is append-only.'));
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(PublisherApplication::class, 'publisher_application_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
