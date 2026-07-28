<?php

namespace App\Http\Controllers\Publisher;

use App\Enums\ContractStatus;
use App\Enums\SiteStatus;
use App\Http\Controllers\Controller;
use App\Models\Publisher;
use App\Models\PublisherContract;
use App\Models\PublisherPaymentProfile;
use App\Models\SiteReview;
use App\Services\Audit\AuditRecorder;
use App\Services\Sites\SiteLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OnboardingController extends Controller
{
    public function show(Request $request, int $step): View
    {
        abort_unless($step >= 1 && $step <= 7, 404);
        $publisher = $this->publisher($request)->load(['contacts', 'paymentProfile', 'contracts', 'sites.domains', 'sites.servingSettings']);

        return view('publisher.onboarding', [
            'step' => $step, 'publisher' => $publisher,
            'contract' => $publisher->contracts->sortByDesc('created_at')->first(),
            'site' => $publisher->sites->sortByDesc('created_at')->first(),
        ]);
    }

    public function update(Request $request, int $step, SiteLifecycleService $lifecycle, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($step >= 1 && $step <= 7, 404);
        $publisher = $this->publisher($request);

        match ($step) {
            1 => $this->company($request, $publisher),
            2 => $this->payment($request, $publisher),
            3 => $this->contract($request, $publisher),
            4 => $this->website($request, $publisher, $lifecycle),
            5 => null,
            6 => $this->placements($request, $publisher),
            7 => $this->submit($request, $publisher, $lifecycle),
        };

        $publisher->update(['onboarding_step' => max($publisher->onboarding_step, min(7, $step + 1))]);
        $audit->record('publisher.onboarding.step_completed', $publisher->organization_id, $request->user(), $publisher, newValues: ['step' => $step]);

        return redirect()->route('publisher.onboarding.show', min(7, $step + 1))->with('status', $step === 7 ? 'Onboarding submitted for Horus Media review.' : 'Step saved.');
    }

    private function company(Request $request, Publisher $publisher): void
    {
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'], 'display_name' => ['required', 'string', 'max:255'], 'billing_email' => ['required', 'email', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'], 'contact_email' => ['required', 'email', 'max:255'], 'contact_phone' => ['nullable', 'string', 'max:40'], 'contact_title' => ['nullable', 'string', 'max:100'],
        ]);
        DB::transaction(function () use ($data, $publisher): void {
            $publisher->update(collect($data)->only(['legal_name', 'display_name', 'billing_email'])->all());
            $publisher->contacts()->updateOrCreate(['is_primary' => true], ['organization_id' => $publisher->organization_id, 'name' => $data['contact_name'], 'email' => $data['contact_email'], 'phone' => $data['contact_phone'] ?? null, 'title' => $data['contact_title'] ?? null]);
        });
    }

    private function payment(Request $request, Publisher $publisher): void
    {
        $profile = $publisher->paymentProfile;
        $data = $request->validate([
            'beneficiary_name' => ['required', 'string', 'max:255'], 'payment_method' => ['required', 'in:BANK_TRANSFER,PAYPAL,WISE,OTHER'],
            'currency' => ['required', 'string', 'size:3'], 'country' => ['required', 'string', 'size:2'], 'billing_address' => ['nullable', 'string', 'max:500'],
            'account_reference' => [Rule::requiredIf(! $profile), 'nullable', 'string', 'max:255'], 'routing_reference' => ['nullable', 'string', 'max:255'], 'tax_identifier' => ['nullable', 'string', 'max:100'],
        ]);
        $values = [
            'organization_id' => $publisher->organization_id, 'beneficiary_name' => $data['beneficiary_name'], 'payment_method' => $data['payment_method'],
            'currency' => strtoupper($data['currency']), 'country' => strtoupper($data['country']), 'billing_address' => $data['billing_address'] ?? null,
            'is_verified' => false,
        ];
        if (! empty($data['account_reference'])) {
            $values['payment_details'] = ['account_reference' => $data['account_reference'], 'routing_reference' => $data['routing_reference'] ?? null];
            $values['account_last_four'] = substr(preg_replace('/\s+/', '', $data['account_reference']), -4);
        }
        if (! empty($data['tax_identifier'])) {
            $values['tax_identifier'] = $data['tax_identifier'];
        }
        PublisherPaymentProfile::updateOrCreate(['publisher_id' => $publisher->id], $values);
    }

    private function contract(Request $request, Publisher $publisher): void
    {
        $contract = $publisher->contracts()->latest()->first();
        $data = $request->validate([
            'contract_reference' => ['required', 'string', 'max:100', Rule::unique('publisher_contracts')->where('publisher_id', $publisher->id)->ignore($contract)],
            'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'], 'auto_renews' => ['sometimes', 'boolean'],
            'revenue_share_percent' => ['required', 'numeric', 'between:0,100'], 'payment_threshold' => ['required', 'numeric', 'min:0'], 'currency' => ['required', 'string', 'size:3'], 'payment_terms' => ['required', 'string', 'max:100'],
        ]);
        PublisherContract::updateOrCreate(['id' => $contract?->id], array_merge($data, ['organization_id' => $publisher->organization_id, 'publisher_id' => $publisher->id, 'created_by' => $request->user()->id, 'status' => $contract?->status ?? ContractStatus::Draft]));
    }

    private function website(Request $request, Publisher $publisher, SiteLifecycleService $lifecycle): void
    {
        $site = $publisher->sites()->latest()->first();
        $request->merge(['primary_domain' => strtolower(rtrim((string) parse_url(str_contains((string) $request->input('primary_domain'), '://') ? $request->input('primary_domain') : 'https://'.$request->input('primary_domain'), PHP_URL_HOST), '.'))]);
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:255'], 'primary_domain' => ['required', 'max:255', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', Rule::unique('sites')->where('publisher_id', $publisher->id)->ignore($site)],
            'language' => ['required', 'string', 'max:12'], 'content_category' => ['required', 'string', 'max:100'], 'country' => ['required', 'string', 'size:2'],
            'estimated_monthly_pageviews' => ['required', 'integer', 'min:0'], 'estimated_monthly_users' => ['required', 'integer', 'min:0'],
            'current_gam_network_code' => ['nullable', 'string', 'max:50'], 'current_adsense_status' => ['nullable', 'string', 'max:24'], 'current_adx_status' => ['nullable', 'string', 'max:24'],
            'prebid_enabled' => ['sometimes', 'boolean'], 'native_demand_enabled' => ['sometimes', 'boolean'], 'default_revenue_share_percent' => ['required', 'numeric', 'between:0,100'],
        ]);
        $data['main_traffic_countries'] = array_values(array_filter(array_map('trim', explode(',', strtoupper((string) $request->input('main_traffic_countries'))))));
        $data['current_monetization_providers'] = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('current_monetization_providers')))));

        if ($site) {
            $oldDomain = $site->primary_domain;
            $site->update($data);
            $site->servingSettings()->update(['prebid_enabled' => $site->prebid_enabled, 'native_demand_enabled' => $site->native_demand_enabled]);
            if ($oldDomain !== $site->primary_domain) {
                $site->domains()->where('is_primary', true)->update(['is_primary' => false]);
                $site->domains()->firstOrCreate(
                    ['domain' => $site->primary_domain],
                    ['organization_id' => $site->organization_id, 'is_primary' => true, 'verification_token' => Str::random(48)],
                )->update(['is_primary' => true]);
            }
        } else {
            $lifecycle->create(array_merge($data, ['organization_id' => $publisher->organization_id, 'publisher_id' => $publisher->id]), $request->user());
        }
    }

    private function placements(Request $request, Publisher $publisher): void
    {
        $data = $request->validate(['placement_formats' => ['required', 'string', 'max:1000'], 'placement_notes' => ['nullable', 'string', 'max:5000']]);
        $site = $publisher->sites()->latest()->firstOrFail();
        $site->servingSettings()->update(['placement_plan' => ['formats' => array_values(array_filter(array_map('trim', explode(',', $data['placement_formats'])))), 'notes' => $data['placement_notes'] ?? null]]);
    }

    private function submit(Request $request, Publisher $publisher, SiteLifecycleService $lifecycle): void
    {
        $request->validate(['confirm' => ['accepted']]);
        $site = $publisher->sites()->latest()->firstOrFail();
        if (in_array($site->status, [SiteStatus::Draft, SiteStatus::PendingVerification, SiteStatus::Rejected], true)) {
            $lifecycle->transition($site, SiteStatus::PendingReview, $request->user(), 'Publisher onboarding submitted.');
            SiteReview::create(['organization_id' => $site->organization_id, 'site_id' => $site->id, 'decision' => 'PENDING', 'submitted_at' => now()]);
        }
        $publisher->update(['onboarding_submitted_at' => now()]);
    }

    private function publisher(Request $request): Publisher
    {
        return Publisher::query()->where('organization_id', $request->user()->organization_id)->firstOrFail();
    }
}
