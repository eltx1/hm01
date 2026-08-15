<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PublisherApplicationLegalAcceptance extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'publisher_application_id', 'user_id', 'document_type', 'document_version',
        'canonical_url', 'accepted_at', 'request_evidence_hash', 'evidence_hash',
    ];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Legal acceptance evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Legal acceptance evidence is immutable.'));
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
