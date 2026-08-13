<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PublisherQualityProfile extends Model
{
    use HasUlids;

    protected $fillable = ['publisher_id', 'version', 'content_categories', 'content_description', 'traffic_profile', 'audience_countries', 'device_mix', 'declarations', 'review_comments', 'created_by'];

    protected function casts(): array
    {
        return ['content_categories' => 'array', 'traffic_profile' => 'array', 'audience_countries' => 'array', 'device_mix' => 'array', 'declarations' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Publisher quality profiles are immutable; create a new version.'));
        static::deleting(fn () => throw new LogicException('Publisher quality profiles are immutable.'));
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }
}
