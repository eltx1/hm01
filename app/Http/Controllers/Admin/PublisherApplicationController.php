<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PublisherApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\PublisherApplication;
use App\Models\PublisherQualityProfile;
use App\Services\PublisherApplications\PublisherApplicationReadinessService;
use App\Services\PublisherApplications\PublisherApplicationService;
use App\Services\Thoth\PublisherQualityReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PublisherApplicationController extends Controller
{
    public function index(Request $request, PublisherApplicationReadinessService $readiness): View
    {
        $status = strtoupper($request->string('status')->value());
        $age = max(0, min(3650, $request->integer('age')));
        $domain = strtolower(trim($request->string('domain')->value()));
        $applicant = trim($request->string('applicant')->value());
        $query = PublisherApplication::withoutGlobalScopes()->with(['publisher', 'applicant'])
            ->when($status === 'NEW', fn ($builder) => $builder->where('status', PublisherApplicationStatus::Submitted->value))
            ->when(PublisherApplicationStatus::tryFrom($status), fn ($builder, $state) => $builder->where('status', $state->value))
            ->when($age > 0, fn ($builder) => $builder->where('created_at', '<=', now()->subDays($age)))
            ->when($domain !== '', fn ($builder) => $builder->where('primary_domain', 'like', '%'.$domain.'%'))
            ->when($applicant !== '', fn ($builder) => $builder->whereHas('applicant', fn ($users) => $users
                ->where('name', 'like', '%'.$applicant.'%')
                ->orWhere('email', 'like', '%'.$applicant.'%')))
            ->orderByRaw('CASE WHEN submitted_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('submitted_at')
            ->latest('created_at');
        $applications = $query->paginate(25)->withQueryString();
        $counts = PublisherApplication::withoutGlobalScopes()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $publisherApplicationReadiness = $readiness->state();

        return view('admin.publisher-applications.index', compact('applications', 'counts', 'status', 'age', 'domain', 'applicant', 'publisherApplicationReadiness'));
    }

    public function show(PublisherApplication $application): View
    {
        $application->load([
            'applicant', 'reviewer', 'organization', 'legalAcceptances',
            'domainClaims' => fn ($query) => $query->with(['publisherSeller', 'websiteSeller'])->latest('created_at'),
            'marketingConsents' => fn ($query) => $query->latest('recorded_at'),
            'publisher.qualityProfiles' => fn ($query) => $query->latest('version'),
            'publisher.qualityReviewRuns' => fn ($query) => $query->latest()->limit(20),
            'revisions' => fn ($query) => $query->latest('version'),
            'events' => fn ($query) => $query->with('actor')->latest(),
        ]);

        return view('admin.publisher-applications.show', compact('application'));
    }

    public function startReview(Request $request, PublisherApplication $application, PublisherApplicationService $applications): RedirectResponse
    {
        $applications->startReview($application, $request->user());

        return back()->with('status', 'Application review started.');
    }

    public function requestInformation(Request $request, PublisherApplication $application, PublisherApplicationService $applications): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']]);
        $applications->requestMoreInformation($application, $request->user(), $data['reason']);

        return back()->with('status', 'The information request was recorded and sent to the applicant.');
    }

    public function thothReview(Request $request, PublisherApplication $application, PublisherQualityReviewService $reviews): RedirectResponse
    {
        if (! in_array($application->status, [
            PublisherApplicationStatus::Submitted,
            PublisherApplicationStatus::UnderReview,
            PublisherApplicationStatus::MoreInfoRequired,
        ], true)) {
            throw ValidationException::withMessages(['thoth' => 'THOTH pre-approval review is not allowed in the current application state.']);
        }

        $profile = PublisherQualityProfile::query()
            ->where('publisher_id', $application->publisher_id)
            ->latest('version')
            ->first();
        if (! $profile) {
            throw ValidationException::withMessages(['profile' => 'Complete the publisher quality profile before running THOTH.']);
        }

        $run = $reviews->runForApplication($application, $profile, $request->user(), $request->boolean('rerun'));

        return back()->with(
            $run->status === 'COMPLETED' ? 'status' : 'error',
            $run->status === 'COMPLETED'
                ? 'THOTH pre-approval website advisory completed. Human Admin review remains authoritative.'
                : 'THOTH pre-approval advisory failed safely: '.$run->error_code,
        );
    }

    public function approve(Request $request, PublisherApplication $application, PublisherApplicationService $applications): RedirectResponse
    {
        $approved = $applications->approve($application, $request->user());

        return redirect()->route('admin.publisher-applications.show', $approved)->with('status', 'Application approved. Reserved HMP/HMS identities are preserved. The account may continue through existing Publisher onboarding; no website or serving configuration was created.');
    }

    public function reject(Request $request, PublisherApplication $application, PublisherApplicationService $applications): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']]);
        $applications->reject($application, $request->user(), $data['reason']);

        return back()->with('status', 'Application rejected. Seller identities and verification evidence have been retained permanently while operational access remains disabled.');
    }
}
