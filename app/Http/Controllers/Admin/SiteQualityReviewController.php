<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SiteStatus;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Thoth\SiteQualityReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class SiteQualityReviewController extends Controller
{
    public function store(Request $request, Site $site, SiteQualityReviewService $reviews): RedirectResponse
    {
        if ($site->status !== SiteStatus::PendingReview) {
            throw ValidationException::withMessages([
                'thoth' => 'THOTH website re-review is available while the website is pending human review.',
            ]);
        }

        $run = $reviews->queueManual($site, $request->user());

        return back()->with(
            'status',
            $run->status === 'FAILED'
                ? 'THOTH could not start: '.$run->error_message.' Human website review remains available.'
                : 'THOTH website advisory queued. Human review remains authoritative and can continue independently.',
        );
    }
}
