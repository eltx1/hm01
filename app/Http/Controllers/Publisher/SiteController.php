<?php

namespace App\Http\Controllers\Publisher;

use App\Enums\SiteStatus;
use App\Http\Controllers\Controller;
use App\Models\Publisher;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteReview;
use App\Services\Audit\AuditRecorder;
use App\Services\Sites\SiteLifecycleService;
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
        $site = $lifecycle->create(array_merge($data, ['organization_id' => $publisher->organization_id, 'publisher_id' => $publisher->id]), $request->user());

        return redirect()->route('publisher.sites.show', $site)->with('status', 'Website created with HORUS_GAM as its serving mode.');
    }

    public function show(Site $site): View
    {
        return view('publisher.sites.show', ['site' => $site->load(['domains.verifications', 'reviews', 'servingSettings']), 'internal' => false]);
    }

    public function edit(Request $request, Site $site): View
    {
        return view('publisher.sites.form', ['site' => $site, 'publisher' => $this->publisher($request)]);
    }

    public function update(Request $request, Site $site, AuditRecorder $audit): RedirectResponse
    {
        $publisher = $this->publisher($request);
        $before = $site->only(['display_name', 'primary_domain', 'language', 'content_category', 'country', 'prebid_enabled', 'native_demand_enabled']);
        $data = $this->validated($request, $publisher, $site);
        $originalDomain = $site->primary_domain;
        $site->update($data);
        $site->servingSettings()->update(['prebid_enabled' => $site->prebid_enabled, 'native_demand_enabled' => $site->native_demand_enabled]);
        if ($originalDomain !== $site->primary_domain) {
            $site->domains()->where('is_primary', true)->update(['is_primary' => false]);
            SiteDomain::firstOrCreate(
                ['site_id' => $site->id, 'domain' => $site->primary_domain],
                ['organization_id' => $site->organization_id, 'is_primary' => true, 'verification_token' => Str::random(48)],
            )->update(['is_primary' => true]);
        }
        $audit->record('site.updated', $site->organization_id, $request->user(), $site, $before, $site->only(array_keys($before)));

        return back()->with('status', 'Website details updated.');
    }

    public function addDomain(Request $request, Site $site, AuditRecorder $audit): RedirectResponse
    {
        $request->merge(['domain' => $this->normalizeDomain((string) $request->input('domain'))]);
        $data = $request->validate(['domain' => ['required', 'max:255', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', Rule::unique('site_domains', 'domain')->where('site_id', $site->id)]]);
        $domain = SiteDomain::create(['organization_id' => $site->organization_id, 'site_id' => $site->id, 'domain' => $data['domain'], 'verification_token' => Str::random(48)]);
        $audit->record('site.domain.added', $site->organization_id, $request->user(), $domain, newValues: ['domain' => $domain->domain]);

        return back()->with('status', 'Authorized domain added.');
    }

    public function submit(Request $request, Site $site, SiteLifecycleService $lifecycle): RedirectResponse
    {
        $lifecycle->transition($site, SiteStatus::PendingReview, $request->user(), 'Submitted by publisher.');
        SiteReview::create(['organization_id' => $site->organization_id, 'site_id' => $site->id, 'decision' => 'PENDING', 'submitted_at' => now()]);

        return back()->with('status', 'Website submitted for review. Domain verification remains visible to the reviewer and does not alter HORUS_GAM availability.');
    }

    private function publisher(Request $request): Publisher
    {
        return Publisher::query()->where('organization_id', $request->user()->organization_id)->firstOrFail();
    }

    private function validated(Request $request, Publisher $publisher, ?Site $site = null): array
    {
        $request->merge([
            'primary_domain' => $this->normalizeDomain((string) $request->input('primary_domain')),
            'main_traffic_countries' => $this->csv((string) $request->input('main_traffic_countries')),
            'current_monetization_providers' => $this->csv((string) $request->input('current_monetization_providers')),
        ]);

        return $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'primary_domain' => ['required', 'max:255', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', Rule::unique('sites')->where('publisher_id', $publisher->id)->ignore($site)],
            'language' => ['required', 'string', 'max:12'], 'content_category' => ['required', 'string', 'max:100'],
            'country' => ['required', 'string', 'size:2'], 'main_traffic_countries' => ['nullable', 'array'], 'main_traffic_countries.*' => ['string', 'size:2'],
            'estimated_monthly_pageviews' => ['required', 'integer', 'min:0'], 'estimated_monthly_users' => ['required', 'integer', 'min:0'],
            'current_monetization_providers' => ['nullable', 'array'], 'current_monetization_providers.*' => ['string', 'max:100'],
            'current_gam_network_code' => ['nullable', 'string', 'max:50'], 'current_adsense_status' => ['nullable', 'string', 'max:24'], 'current_adx_status' => ['nullable', 'string', 'max:24'],
            'prebid_enabled' => ['sometimes', 'boolean'], 'native_demand_enabled' => ['sometimes', 'boolean'],
            'default_revenue_share_percent' => ['required', 'numeric', 'between:0,100'],
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
