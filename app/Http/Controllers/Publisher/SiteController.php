<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Publisher;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Sites\SiteAdsTxtInstallationService;
use App\Services\Sites\SiteLifecycleService;
use App\Services\Sites\SiteReviewSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SiteController extends Controller
{
    public function index(Request $request): View
    {
        return view('publisher.sites.index', ['sites' => Site::query()->latest()->paginate(20), 'publisher' => $this->publisher($request)]);
    }

    public function create(Request $request): View
    {
        return view('publisher.sites.form', ['site' => new Site, 'publisher' => $this->publisher($request)]);
    }

    public function store(Request $request, SiteLifecycleService $lifecycle): RedirectResponse
    {
        $publisher = $this->publisher($request);
        $data = $this->validated($request, $publisher);
        $data['default_revenue_share_percent'] = $publisher->applicableRevenueShare();
        $site = $lifecycle->create(array_merge($data, ['organization_id' => $publisher->organization_id, 'publisher_id' => $publisher->id]), $request->user());

        return redirect()->route('publisher.sites.show', $site)->with('status', 'Website created. Publish the complete ads.txt block, then verify it. Successful verification submits the website for review automatically.');
    }

    public function show(Site $site, SiteAdsTxtInstallationService $adsTxt): View
    {
        return view('publisher.sites.show', [
            'site' => $site->load(['domains.verifications', 'reviews', 'servingSettings', 'publisher']),
            'internal' => false,
            'adsTxtInstallation' => $adsTxt->bundle($site),
        ]);
    }

    public function edit(Request $request, Site $site): View
    {
        return view('publisher.sites.form', ['site' => $site, 'publisher' => $this->publisher($request)]);
    }

    public function update(Request $request, Site $site, AuditRecorder $audit, SiteConfigPublisher $configPublisher): RedirectResponse
    {
        $publisher = $this->publisher($request);
        $before = $site->only(['display_name', 'primary_domain', 'language', 'content_category', 'country', 'prebid_enabled', 'native_demand_enabled']);
        $runtimeBefore = $site->only(['primary_domain', 'current_gam_network_code', 'prebid_enabled', 'native_demand_enabled']);
        $data = $this->validated($request, $publisher, $site);
        $originalDomain = $site->primary_domain;
        $site->update($data);
        $settings = $site->servingSettings()->firstOrFail();
        $configurationChanged = $settings->prebid_enabled !== $site->prebid_enabled
            || $settings->native_demand_enabled !== $site->native_demand_enabled;
        $settings->update([
            'prebid_enabled' => $site->prebid_enabled,
            'native_demand_enabled' => $site->native_demand_enabled,
            'configuration_version' => $configurationChanged ? $settings->configuration_version + 1 : $settings->configuration_version,
        ]);
        if ($originalDomain !== $site->primary_domain) {
            $site->domains()->where('is_primary', true)->update(['is_primary' => false]);
            SiteDomain::firstOrCreate(
                ['site_id' => $site->id, 'domain' => $site->primary_domain],
                ['organization_id' => $site->organization_id, 'is_primary' => true, 'verification_token' => Str::random(48)],
            )->update(['is_primary' => true]);
        }
        $audit->record('site.updated', $site->organization_id, $request->user(), $site, $before, $site->only(array_keys($before)));
        $version = $runtimeBefore !== $site->fresh()->only(array_keys($runtimeBefore))
            ? $configPublisher->publishActiveProduction($site, $request->user())
            : null;

        return back()->with('status', $version
            ? 'Website details updated and production configuration v'.$version->version.' was queued automatically.'
            : 'Website details updated. Runtime changes will publish automatically when the website is active.');
    }

    public function addDomain(Request $request, Site $site, AuditRecorder $audit, SiteConfigPublisher $configPublisher): RedirectResponse
    {
        $request->merge(['domain' => $this->normalizeDomain((string) $request->input('domain'))]);
        $data = $request->validate(['domain' => ['required', 'max:255', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', Rule::unique('site_domains', 'domain')->where('site_id', $site->id)]]);
        $domain = SiteDomain::create(['organization_id' => $site->organization_id, 'site_id' => $site->id, 'domain' => $data['domain'], 'verification_token' => Str::random(48)]);
        $audit->record('site.domain.added', $site->organization_id, $request->user(), $domain, newValues: ['domain' => $domain->domain]);
        $version = $configPublisher->publishActiveProduction($site, $request->user());

        return back()->with('status', $version
            ? 'Authorized domain added and production configuration v'.$version->version.' was queued automatically.'
            : 'Authorized domain added. It will publish automatically when the website is activated.');
    }

    public function submit(Request $request, Site $site, SiteReviewSubmissionService $submission): RedirectResponse
    {
        // Legacy endpoint compatibility. The current UI submits automatically
        // after ads.txt verification; any early legacy submission still cannot
        // be approved or activated until the real HMP/HMS check passes.
        $submission->submitIfReady($site, $request->user(), requireVerification: false);

        return back()->with('status', 'Website submitted for review. Approval will activate it automatically.');
    }

    private function publisher(Request $request): Publisher
    {
        return Publisher::query()->where('organization_id', $request->user()->organization_id)->firstOrFail();
    }

    private function validated(Request $request, Publisher $publisher, ?Site $site = null): array
    {
        $request->merge([
            'primary_domain' => $this->normalizeDomain((string) $request->input('primary_domain')),
            'country' => strtoupper(trim((string) $request->input('country'))),
            'main_traffic_countries' => $this->csv((string) $request->input('main_traffic_countries')),
            'current_monetization_providers' => $this->csv((string) $request->input('current_monetization_providers')),
        ]);

        if (! $site) {
            $data = $request->validate([
                'display_name' => ['required', 'string', 'max:255'],
                'primary_domain' => ['required', 'max:255', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', Rule::unique('sites')->where('publisher_id', $publisher->id)],
                'content_category' => ['required', 'string', Rule::in(['NEWS', 'ENTERTAINMENT', 'SPORTS', 'TECHNOLOGY', 'LIFESTYLE', 'BUSINESS', 'OTHER'])],
                'country' => ['required', 'string', 'size:2'],
            ]);

            return $data + [
                'language' => 'en',
                'main_traffic_countries' => [$data['country']],
                'estimated_monthly_pageviews' => 0,
                'estimated_monthly_users' => 0,
                'current_monetization_providers' => [],
                'current_gam_network_code' => null,
                'current_adsense_status' => null,
                'current_adx_status' => null,
                'prebid_enabled' => false,
                'native_demand_enabled' => false,
            ];
        }

        return $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'primary_domain' => ['required', 'max:255', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', Rule::unique('sites')->where('publisher_id', $publisher->id)->ignore($site)],
            'language' => ['required', 'string', 'max:12'], 'content_category' => ['required', 'string', 'max:100'],
            'country' => ['required', 'string', 'size:2'], 'main_traffic_countries' => ['nullable', 'array'], 'main_traffic_countries.*' => ['string', 'size:2'],
            'estimated_monthly_pageviews' => ['required', 'integer', 'min:0'], 'estimated_monthly_users' => ['required', 'integer', 'min:0'],
            'current_monetization_providers' => ['nullable', 'array'], 'current_monetization_providers.*' => ['string', 'max:100'],
            'current_gam_network_code' => ['nullable', 'string', 'max:50'], 'current_adsense_status' => ['nullable', 'string', 'max:24'], 'current_adx_status' => ['nullable', 'string', 'max:24'],
            'prebid_enabled' => ['sometimes', 'boolean'], 'native_demand_enabled' => ['sometimes', 'boolean'],
        ]);
    }

    private function normalizeDomain(string $domain): string
    {
        $host = parse_url(str_contains($domain, '://') ? $domain : 'https://'.$domain, PHP_URL_HOST);

        return strtolower(rtrim((string) $host, '.'));
    }

    private function csv(string $value): array
    {
        return array_values(array_filter(array_map(fn (string $item) => strtoupper(trim($item)), explode(',', $value))));
    }
}
