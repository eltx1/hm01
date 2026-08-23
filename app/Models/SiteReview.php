<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Services\Thoth\SiteQualityReviewService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Throwable;

class SiteReview extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['organization_id', 'site_id', 'reviewer_id', 'decision', 'publisher_message', 'internal_reason', 'submitted_at', 'reviewed_at'];

    protected $hidden = ['internal_reason'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::created(function (self $review): void {
            if ($review->decision !== 'PENDING') {
                return;
            }

            try {
                $site = Site::withoutGlobalScopes()->find($review->site_id);
                if (! $site) {
                    return;
                }

                $actorId = $site->statusHistory()->latest('created_at')->value('changed_by');
                $actor = $actorId ? User::query()->find($actorId) : null;
                app(SiteQualityReviewService::class)->queueAutomatic($site, $actor);
            } catch (Throwable $exception) {
                Log::warning('Automatic THOTH website review could not be queued; website review continues normally.', [
                    'site_id' => $review->site_id,
                    'exception' => $exception::class,
                ]);
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
