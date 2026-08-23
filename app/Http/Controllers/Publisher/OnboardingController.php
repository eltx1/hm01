<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Publisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OnboardingController extends Controller
{
    /**
     * The former seven-step onboarding flow is intentionally retired.
     *
     * Keep the legacy GET route as a compatibility redirect so old bookmarks,
     * notifications, or browser history never strand an approved Publisher.
     * The current product flow is Dashboard -> Websites -> ads.txt -> review.
     */
    public function show(Request $request, int $step): RedirectResponse
    {
        abort_unless($step >= 1 && $step <= 7, 404);
        $this->publisher($request);

        return redirect()->route('publisher.sites.index')->with(
            'status',
            'Publisher setup is now handled directly from your dashboard. Add or manage a website to continue.',
        );
    }

    /**
     * Never execute mutations from an old onboarding form.
     *
     * A stale tab may still submit one of the historical PUT requests after a
     * deployment. Redirect it safely instead of writing legacy payment, site,
     * placement, or review state back into the current product model.
     */
    public function update(Request $request, int $step): RedirectResponse
    {
        abort_unless($step >= 1 && $step <= 7, 404);
        $this->publisher($request);

        return redirect()->route('publisher.sites.index')->with(
            'status',
            'The old onboarding form has been retired. No legacy changes were applied.',
        );
    }

    private function publisher(Request $request): Publisher
    {
        return Publisher::query()
            ->where('organization_id', $request->user()->organization_id)
            ->firstOrFail();
    }
}
