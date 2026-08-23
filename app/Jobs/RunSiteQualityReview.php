<?php

namespace App\Jobs;

use App\Models\SiteQualityReviewRun;
use App\Services\Thoth\SiteQualityReviewService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class RunSiteQualityReview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly string $runId) {}

    public function handle(SiteQualityReviewService $reviews): void
    {
        $run = SiteQualityReviewRun::query()->find($this->runId);
        if (! $run) {
            return;
        }

        try {
            $reviews->execute($run);
        } catch (Throwable) {
            // Provider/service failures are persisted by the service. A final guard keeps
            // this optional advisory from becoming a platform failed-job dependency.
        }
    }
}
