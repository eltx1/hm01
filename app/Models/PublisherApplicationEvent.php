<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PublisherApplicationEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['publisher_application_id', 'publisher_application_revision_id', 'actor_id', 'action', 'previous_status', 'new_status', 'reason', 'applicant_visible'];

    protected function casts(): array
    {
        return ['applicant_visible' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Publisher application events are immutable.'));
        static::deleting(fn () => throw new LogicException('Publisher application events are immutable.'));
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(PublisherApplication::class, 'publisher_application_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
