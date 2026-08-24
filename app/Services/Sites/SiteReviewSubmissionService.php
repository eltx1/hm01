<?php

namespace App\Services\Sites;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\SiteReview;
use App\Models\User;
use App\Services\Thoth\SiteQualityReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SiteReviewSubmissionService
{
    public function __construct(
        private readonly SiteLifecycleService $lifecycle,
        private readonly SiteAdsTxtInstallationService $adsTxt,
    ) {}

    /**
     * Submit exactly once after current HMP/HMS ads.txt verification. Returns
     * false when the website is already in or beyond review.
     */
    public function submitIfReady(Site $site, User $actor, bool $requireVerification = true): bool
    {
        $submitted = DB::transaction(function () use ($site, $actor, $requireVerification): bool {
            $locked = Site::withoutGlobalScopes()->lockForUpdate()->findOrFail($site->id);
            if (in_array($locked->status, [SiteStatus::PendingReview, SiteStatus::Approved, SiteStatus::Active], true)) {
                return false;
            }
            if (! in_array($locked->status, [SiteStatus::Draft, SiteStatus::PendingVerification, SiteStatus::Rejected], true)) {
                throw ValidationException::withMessages(['website' => 'This website cannot be submitted from its current state.']);
            }
            if ($requireVerification && ! $this->adsTxt->hasCurrentCoreVerification($locked)) {
                throw ValidationException::withMessages([
                    'website_verification' => 'Verify both assigned Horus HMP/HMS DIRECT ads.txt records before website review.',
                ]);
            }

            $this->lifecycle->transition($locked, SiteStatus::PendingReview, $actor, 'Submitted automatically after ads.txt verification.');
            SiteReview::withoutGlobalScopes()->create([
                'organization_id' => $locked->organization_id,
                'site_id' => $locked->id,
                'decision' => 'PENDING',
                'submitted_at' => now(),
            ]);

            return true;
        });

        if (! $submitted) {
            return false;
        }

        try {
            // Resolve THOTH lazily so even a broken provider binding cannot
            // prevent the already-valid human review submission.
            app(SiteQualityReviewService::class)->queueAutomatic($site->fresh(), $actor);
        } catch (Throwable $exception) {
            Log::warning('Automatic THOTH website review could not start; website submission remains valid.', [
                'site_id' => $site->id,
                'exception' => $exception::class,
            ]);
        }

        return true;
    }
}
