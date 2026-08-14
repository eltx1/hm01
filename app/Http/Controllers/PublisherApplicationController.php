<?php

namespace App\Http\Controllers;

use App\Models\PublisherApplication;
use App\Models\PublisherQualityProfile;
use App\Services\PublisherApplications\PublisherApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class PublisherApplicationController extends Controller
{
    private const COUNTRIES = ['US', 'GB', 'CA', 'AU', 'AE', 'SA', 'EG', 'FR', 'DE', 'IN', 'OTHER'];

    public function show(Request $request): View
    {
        $application = PublisherApplication::withoutGlobalScopes()
            ->where('applicant_user_id', $request->user()->id)
            ->where('organization_id', $request->user()->organization_id)
            ->with([
                'publisher',
                'events' => fn ($query) => $query->where('applicant_visible', true)->latest(),
                'revisions' => fn ($query) => $query->latest('version'),
            ])
            ->firstOrFail();
        $profile = PublisherQualityProfile::query()->where('publisher_id', $application->publisher_id)->latest('version')->first();

        return view('publisher-applications.show', compact('application', 'profile'));
    }

    public function update(Request $request, PublisherApplicationService $applications): RedirectResponse
    {
        $data = $request->validate([
            'contact_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['required', 'string', 'max:255'],
            'publisher_name' => ['required', 'string', 'max:255'],
            'primary_domain' => ['required', 'string', 'max:500'],
            'content_categories' => ['nullable', 'array', 'max:20'],
            'content_categories.*' => ['string', 'max:100'],
            'content_description' => ['nullable', 'string', 'max:10000'],
            'monthly_pageviews' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'organic_percent' => ['nullable', 'integer', 'between:0,100'],
            'social_percent' => ['nullable', 'integer', 'between:0,100'],
            'direct_percent' => ['nullable', 'integer', 'between:0,100'],
            'paid_percent' => ['nullable', 'integer', 'between:0,100'],
            'other_percent' => ['nullable', 'integer', 'between:0,100'],
            'audience_countries' => ['nullable', 'array', 'max:50'],
            'audience_countries.*' => ['string', Rule::in(self::COUNTRIES)],
            'desktop_percent' => ['nullable', 'integer', 'between:0,100'],
            'mobile_percent' => ['nullable', 'integer', 'between:0,100'],
            'tablet_percent' => ['nullable', 'integer', 'between:0,100'],
            'original_content' => ['sometimes', 'boolean'],
            'user_generated_content' => ['sometimes', 'boolean'],
            'ai_assisted_content' => ['sometimes', 'boolean'],
            'sensitive_content' => ['sometimes', 'boolean'],
            'has_privacy_policy' => ['sometimes', 'boolean'],
            'has_contact_details' => ['sometimes', 'boolean'],
            'has_cmp' => ['sometimes', 'boolean'],
            'prior_policy_incidents' => ['sometimes', 'boolean'],
            'monetization_history' => ['nullable', 'string', 'max:5000'],
            'application_notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $applications->saveDraft($request->user(), $data);

        return back()->with('status', 'Draft saved. You can sign out and continue later.');
    }

    public function submit(Request $request, PublisherApplicationService $applications): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']]);
        $applications->submit($request->user());

        return back()->with('status', 'Application submitted for Horus Media review. Publisher access and ad serving remain inactive.');
    }

    public function withdraw(Request $request, PublisherApplicationService $applications): RedirectResponse
    {
        $request->validate(['confirm_withdrawal' => ['accepted']]);
        $applications->withdraw($request->user());

        return back()->with('status', 'Application withdrawn. Security and review evidence has been retained.');
    }
}
