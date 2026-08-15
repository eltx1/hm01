<?php

namespace App\Http\Controllers;

use App\Enums\PublisherApplicationStatus;
use App\Models\PublisherApplication;
use App\Models\PublisherQualityProfile;
use App\Services\PublisherApplications\PublisherApplicationLegalService;
use App\Services\PublisherApplications\PublisherApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PublisherApplicationController extends Controller
{
    private const COUNTRIES = ['US', 'GB', 'CA', 'AU', 'AE', 'SA', 'EG', 'FR', 'DE', 'IN', 'OTHER'];

    public function show(Request $request, PublisherApplicationLegalService $legal): View
    {
        $application = $this->applicationFor($request)->load([
            'publisher',
            'legalAcceptances',
            'marketingConsents' => fn ($query) => $query->latest('recorded_at'),
            'events' => fn ($query) => $query->where('applicant_visible', true)->latest(),
            'revisions' => fn ($query) => $query->latest('version'),
        ]);
        $profile = PublisherQualityProfile::query()->where('publisher_id', $application->publisher_id)->latest('version')->first();
        $editable = $request->user()->hasVerifiedEmail() && $application->status->applicantMayEdit();
        $step = $request->user()->hasVerifiedEmail() ? max(2, min(5, $request->integer('step', 2))) : 1;
        if (! $editable && ! in_array($application->status, [PublisherApplicationStatus::EmailVerificationRequired, PublisherApplicationStatus::Draft, PublisherApplicationStatus::MoreInfoRequired], true)) {
            $step = 5;
        }
        $legalDocuments = $legal->documents();
        $acceptedLegal = $application->legalAcceptances->keyBy(fn ($acceptance) => $acceptance->document_type.'@'.$acceptance->document_version);
        $marketingConsent = $application->marketingConsents->first();

        return view('publisher-applications.show', compact('application', 'profile', 'step', 'editable', 'legalDocuments', 'acceptedLegal', 'marketingConsent'));
    }

    public function update(Request $request, PublisherApplicationService $applications, PublisherApplicationLegalService $legal): RedirectResponse
    {
        $step = $request->integer('step');
        if ($step === 0) {
            $applications->saveDraft($request->user(), $this->validateLegacyDraft($request));
            return back()->with('status', 'Draft saved. You can sign out and continue later.');
        }

        $application = $this->applicationFor($request);
        if (! $application->status->applicantMayEdit()) {
            throw ValidationException::withMessages(['application' => 'This application cannot be edited in its current state.']);
        }

        if ($step === 2) {
            $data = $request->validate([
                'contact_name' => ['required', 'string', 'max:255'],
                'legal_name' => ['required', 'string', 'max:255'],
                'publisher_name' => ['required', 'string', 'max:255'],
                'primary_domain' => ['required', 'string', 'max:500'],
            ]);
            $applications->saveDraft($request->user(), array_merge($this->currentDraftPayload($application), $data));
            return redirect()->route('publisher-application.show', ['step' => 3])->with('status', 'Website and Publisher details saved.');
        }

        if ($step === 3) {
            $data = $request->validate($this->qualityRules());
            $applications->saveDraft($request->user(), array_merge($this->currentDraftPayload($application), $data));
            return redirect()->route('publisher-application.show', ['step' => 4])->with('status', 'Quality and traffic information saved.');
        }

        if ($step === 4) {
            $data = $request->validate([
                'legal' => ['nullable', 'array'],
                'marketing_opt_in' => ['nullable', 'boolean'],
            ]);
            $legal->record($application, $request->user(), $data, $request);
            return redirect()->route('publisher-application.show', ['step' => 5])->with('status', 'Agreement choices recorded.');
        }

        throw ValidationException::withMessages(['step' => 'Choose a valid application step.']);
    }

    public function submit(Request $request, PublisherApplicationService $applications, PublisherApplicationLegalService $legal): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']]);
        $application = $this->applicationFor($request);
        $legal->assertCurrentRequiredAccepted($application, $request->user());
        $applications->submit($request->user());

        return redirect()->route('publisher-application.show', ['step' => 5])
            ->with('status', 'Application submitted for Horus Media review. Publisher access and ad serving remain inactive.');
    }

    public function withdraw(Request $request, PublisherApplicationService $applications): RedirectResponse
    {
        $request->validate(['confirm_withdrawal' => ['accepted']]);
        $applications->withdraw($request->user());

        return back()->with('status', 'Application withdrawn. Security and review evidence has been retained.');
    }

    private function applicationFor(Request $request): PublisherApplication
    {
        return PublisherApplication::withoutGlobalScopes()
            ->where('applicant_user_id', $request->user()->id)
            ->where('organization_id', $request->user()->organization_id)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function currentDraftPayload(PublisherApplication $application): array
    {
        $application->loadMissing(['publisher', 'applicant']);
        $profile = PublisherQualityProfile::query()->where('publisher_id', $application->publisher_id)->latest('version')->first();
        $traffic = $profile?->traffic_profile ?? [];
        $devices = $profile?->device_mix ?? [];
        $declarations = $profile?->declarations ?? [];

        return [
            'contact_name' => $application->applicant->name,
            'legal_name' => $application->publisher->legal_name,
            'publisher_name' => $application->publisher->display_name,
            'primary_domain' => $application->primary_domain,
            'content_categories' => $profile?->content_categories ?? [],
            'content_description' => $profile?->content_description ?? '',
            'monthly_pageviews' => $traffic['monthly_pageviews'] ?? null,
            'organic_percent' => $traffic['organic'] ?? 0,
            'social_percent' => $traffic['social'] ?? 0,
            'direct_percent' => $traffic['direct'] ?? 100,
            'paid_percent' => $traffic['paid'] ?? 0,
            'other_percent' => $traffic['other'] ?? 0,
            'audience_countries' => $profile?->audience_countries ?? [],
            'desktop_percent' => $devices['desktop'] ?? 100,
            'mobile_percent' => $devices['mobile'] ?? 0,
            'tablet_percent' => $devices['tablet'] ?? 0,
            'original_content' => (bool) ($declarations['original_content'] ?? false),
            'user_generated_content' => (bool) ($declarations['user_generated_content'] ?? false),
            'ai_assisted_content' => (bool) ($declarations['ai_assisted_content'] ?? false),
            'sensitive_content' => (bool) ($declarations['sensitive_content'] ?? false),
            'has_privacy_policy' => (bool) ($declarations['has_privacy_policy'] ?? false),
            'has_contact_details' => (bool) ($declarations['has_contact_details'] ?? false),
            'has_cmp' => (bool) ($declarations['has_cmp'] ?? false),
            'prior_policy_incidents' => (bool) ($declarations['prior_policy_incidents'] ?? false),
            'monetization_history' => $traffic['monetization_history'] ?? null,
            'application_notes' => $profile?->review_comments,
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function qualityRules(): array
    {
        return [
            'content_categories' => ['required', 'array', 'min:1', 'max:20'],
            'content_categories.*' => ['string', 'max:100'],
            'content_description' => ['required', 'string', 'max:10000'],
            'monthly_pageviews' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'organic_percent' => ['required', 'integer', 'between:0,100'],
            'social_percent' => ['required', 'integer', 'between:0,100'],
            'direct_percent' => ['required', 'integer', 'between:0,100'],
            'paid_percent' => ['required', 'integer', 'between:0,100'],
            'other_percent' => ['required', 'integer', 'between:0,100'],
            'audience_countries' => ['required', 'array', 'min:1', 'max:50'],
            'audience_countries.*' => ['string', Rule::in(self::COUNTRIES)],
            'desktop_percent' => ['required', 'integer', 'between:0,100'],
            'mobile_percent' => ['required', 'integer', 'between:0,100'],
            'tablet_percent' => ['required', 'integer', 'between:0,100'],
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
        ];
    }

    /** @return array<string, mixed> */
    private function validateLegacyDraft(Request $request): array
    {
        return $request->validate(array_merge([
            'contact_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['required', 'string', 'max:255'],
            'publisher_name' => ['required', 'string', 'max:255'],
            'primary_domain' => ['required', 'string', 'max:500'],
        ], $this->qualityRules()));
    }
}
