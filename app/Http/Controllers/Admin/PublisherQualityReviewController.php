<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PublisherApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Publisher;
use App\Models\PublisherQualityDecision;
use App\Models\PublisherQualityProfile;
use App\Models\PublisherQualityReviewRun;
use App\Services\Audit\AuditRecorder;
use App\Services\Thoth\PublisherQualityReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PublisherQualityReviewController extends Controller
{
    public function profile(Request $request, Publisher $publisher, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'content_categories' => ['required', 'array', 'min:1', 'max:20'], 'content_categories.*' => ['string', 'max:100'], 'content_description' => ['required', 'string', 'max:10000'],
            'monthly_pageviews' => ['nullable', 'integer', 'min:0', 'max:100000000000'], 'organic_percent' => ['required', 'integer', 'between:0,100'], 'social_percent' => ['required', 'integer', 'between:0,100'], 'direct_percent' => ['required', 'integer', 'between:0,100'], 'paid_percent' => ['required', 'integer', 'between:0,100'], 'other_percent' => ['required', 'integer', 'between:0,100'],
            'audience_countries' => ['required', 'array', 'min:1', 'max:50'], 'audience_countries.*' => ['string', Rule::in(['US', 'GB', 'CA', 'AU', 'AE', 'SA', 'EG', 'FR', 'DE', 'IN', 'OTHER'])],
            'desktop_percent' => ['required', 'integer', 'between:0,100'], 'mobile_percent' => ['required', 'integer', 'between:0,100'], 'tablet_percent' => ['required', 'integer', 'between:0,100'],
            'original_content' => ['nullable', 'boolean'], 'user_generated_content' => ['nullable', 'boolean'], 'ai_assisted_content' => ['nullable', 'boolean'], 'sensitive_content' => ['nullable', 'boolean'], 'has_privacy_policy' => ['nullable', 'boolean'], 'has_contact_details' => ['nullable', 'boolean'], 'has_cmp' => ['nullable', 'boolean'], 'prior_policy_incidents' => ['nullable', 'boolean'], 'monetization_history' => ['nullable', 'string', 'max:5000'], 'review_comments' => ['nullable', 'string', 'max:10000'],
        ]);
        if ($data['organic_percent'] + $data['social_percent'] + $data['direct_percent'] + $data['paid_percent'] + $data['other_percent'] !== 100) {
            throw ValidationException::withMessages(['direct_percent' => 'Traffic source percentages must total 100.']);
        }
        if ($data['desktop_percent'] + $data['mobile_percent'] + $data['tablet_percent'] !== 100) {
            throw ValidationException::withMessages(['desktop_percent' => 'Device percentages must total 100.']);
        }
        $version = ((int) PublisherQualityProfile::query()->where('publisher_id', $publisher->id)->max('version')) + 1;
        $profile = PublisherQualityProfile::create(['publisher_id' => $publisher->id, 'version' => $version, 'content_categories' => $data['content_categories'], 'content_description' => $data['content_description'], 'traffic_profile' => ['monthly_pageviews' => $data['monthly_pageviews'] ?? null, 'organic' => $data['organic_percent'], 'social' => $data['social_percent'], 'direct' => $data['direct_percent'], 'paid' => $data['paid_percent'], 'other' => $data['other_percent'], 'monetization_history' => $data['monetization_history'] ?? null], 'audience_countries' => array_map('strtoupper', $data['audience_countries']), 'device_mix' => ['desktop' => $data['desktop_percent'], 'mobile' => $data['mobile_percent'], 'tablet' => $data['tablet_percent']], 'declarations' => collect(['original_content', 'user_generated_content', 'ai_assisted_content', 'sensitive_content', 'has_privacy_policy', 'has_contact_details', 'has_cmp', 'prior_policy_incidents'])->mapWithKeys(fn ($key) => [$key => $request->boolean($key)])->all(), 'review_comments' => $data['review_comments'] ?? null, 'created_by' => $request->user()->id]);
        $audit->record('thoth.profile.created', $publisher->organization_id, $request->user(), $profile, metadata: ['version' => $version]);

        return back()->with('status', 'Publisher quality profile version '.$version.' saved.');
    }

    public function run(Request $request, Publisher $publisher, PublisherQualityReviewService $reviews): RedirectResponse
    {
        $profile = PublisherQualityProfile::query()->where('publisher_id', $publisher->id)->latest('version')->first();
        if (! $profile) {
            throw ValidationException::withMessages(['profile' => 'Complete the publisher quality profile first.']);
        }
        $run = $reviews->run($publisher, $profile, $request->user(), $request->boolean('rerun'));

        return back()->with($run->status === 'COMPLETED' ? 'status' : 'error', $run->status === 'COMPLETED' ? 'THOTH advisory completed.' : 'THOTH advisory failed safely: '.$run->error_code);
    }

    public function decide(Request $request, Publisher $publisher, AuditRecorder $audit): RedirectResponse
    {
        if ($publisher->application()->where('status', '!=', PublisherApplicationStatus::Approved->value)->exists()) {
            throw ValidationException::withMessages(['decision' => 'THOTH is advisory only. Use the Publisher Applications workflow for the application decision.']);
        }
        $data = $request->validate(['decision' => ['required', 'in:APPROVE,REJECT,NEEDS_INFORMATION'], 'reason' => ['required', 'string', 'max:5000'], 'review_run_id' => ['nullable', 'ulid', 'exists:publisher_quality_review_runs,id']]);
        if ($data['decision'] === 'APPROVE' && ! $publisher->onboarding_submitted_at) {
            throw ValidationException::withMessages(['decision' => 'The publisher must submit onboarding before approval.']);
        }
        $run = isset($data['review_run_id']) ? PublisherQualityReviewRun::query()->where('publisher_id', $publisher->id)->findOrFail($data['review_run_id']) : null;
        $status = $data['decision'] === 'APPROVE' ? 'ACTIVE' : ($data['decision'] === 'REJECT' ? 'SUSPENDED' : $publisher->status->value);
        $decision = DB::transaction(function () use ($publisher, $request, $data, $run, $status) {
            $decision = PublisherQualityDecision::create(['publisher_id' => $publisher->id, 'review_run_id' => $run?->id, 'decision' => $data['decision'], 'reason' => $data['reason'], 'previous_status' => $publisher->status->value, 'new_status' => $status, 'decided_by' => $request->user()->id]);
            if ($status !== $publisher->status->value) {
                $publisher->update(['status' => $status]);
                $publisher->organization->update(['status' => $status]);
            }

            return $decision;
        });
        $audit->record('publisher.reviewed', $publisher->organization_id, $request->user(), $publisher, ['status' => $decision->previous_status], ['status' => $decision->new_status], ['decision' => $data['decision'], 'reason' => $data['reason'], 'quality_decision_id' => $decision->id, 'review_run_id' => $run?->id]);

        return back()->with('status', 'Human publisher decision recorded.');
    }
}
