<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\FinancialReportingMethod;
use App\Enums\OrganizationType;
use App\Http\Controllers\Controller;
use App\Models\DemandAccount;
use App\Models\DemandNetwork;
use App\Models\DemandPlacement;
use App\Models\DemandSite;
use App\Models\Organization;
use App\Models\Placement;
use App\Models\Publisher;
use App\Models\ReportSource;
use App\Models\Site;
use App\Services\Audit\AuditRecorder;
use App\Services\Demand\DemandAccountService;
use App\Services\Demand\DemandConnectorManager;
use App\Services\Demand\DemandGamDeploymentService;
use App\Services\Demand\DemandReportService;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Operations\PlatformControlService;
use App\Services\Reporting\MonetizationFinancialBindingService;
use App\Services\Reporting\MonetizationFinancialReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use JsonException;

class DemandNetworkController extends Controller
{
    public function index(DemandReportService $reports, MonetizationFinancialReadinessService $financialReadiness): View
    {
        $accounts = DemandAccount::withoutGlobalScopes()
            ->with(['network', 'publisher', 'sites.site', 'credentials'])
            ->latest()
            ->paginate(30);

        return view('admin.demand.index', [
            'networks' => DemandNetwork::query()->withCount('accounts')->orderBy('name')->get(),
            'accounts' => $accounts,
            'publishers' => Publisher::withoutGlobalScopes()->with('organization')->orderBy('display_name')->get(),
            'partners' => Organization::withoutGlobalScopes()->where('type', OrganizationType::Partner->value)->orderBy('name')->get(),
            'scopes' => DemandAccountScope::cases(),
            'modes' => DemandIntegrationMode::cases(),
            'statuses' => DemandApprovalStatus::cases(),
            'summaries' => $accounts->getCollection()->mapWithKeys(fn ($account) => [$account->id => $reports->summary($account)]),
            'financialStatuses' => $accounts->getCollection()->mapWithKeys(fn ($account) => [$account->id => $financialReadiness->status($account)]),
            'reportSources' => ReportSource::query()->where('is_enabled', true)->orderBy('name')->get(),
            'financialMethods' => FinancialReportingMethod::cases(),
            'directDemandMasterEnabled' => ! app(PlatformControlService::class)->disabled('PLATFORM', null, 'DIRECT_JS'),
        ]);
    }

    public function site(Site $site, DemandGamDeploymentService $gam): View
    {
        $site->load(['publisher', 'placements.adUnit', 'placements.sizes']);
        $mappings = DemandSite::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->with(['account.network', 'placements.placement', 'placements.widgets', 'account.adsTxtRecords'])
            ->get();

        $plans = [];
        foreach ($mappings as $mapping) {
            try {
                $plans[$mapping->id] = $gam->preview($mapping);
            } catch (\Throwable $exception) {
                $plans[$mapping->id] = ['issues' => [$exception->getMessage()], 'estimatedObjects' => 0, 'pendingObjects' => 0];
            }
        }

        return view('admin.demand.site', [
            'site' => $site,
            'mappings' => $mappings,
            'accounts' => DemandAccount::withoutGlobalScopes()
                ->with('network')
                ->where('is_enabled', true)
                ->orderBy('fallback_priority')
                ->get(),
            'modes' => DemandIntegrationMode::cases(),
            'statuses' => DemandApprovalStatus::cases(),
            'plans' => $plans,
        ]);
    }

    public function toggleSiteNative(Request $request, Site $site, SiteConfigPublisher $publisher, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $before = (bool) $site->native_demand_enabled;
        $site->update(['native_demand_enabled' => (bool) $data['enabled']]);
        $audit->record('demand.site.direct_demand_enabled_changed', $site->organization_id, $request->user(), $site,
            ['native_demand_enabled' => $before], ['native_demand_enabled' => (bool) $data['enabled']]);
        $publisher->publishActiveProduction($site, $request->user());

        return back()->with('status', $site->native_demand_enabled ? 'Direct Demand enabled for this website.' : 'Direct Demand disabled and removed from the current configuration.');
    }

    public function toggleMaster(Request $request, PlatformControlService $controls, SiteConfigPublisher $publisher): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:10000'],
        ]);
        $controls->set('PLATFORM', null, 'DIRECT_JS', ! (bool) $data['enabled'], $data['reason'], $request->user());
        Site::withoutGlobalScopes()->where('native_demand_enabled', true)->get()->each(
            fn (Site $site) => $publisher->publishActiveProduction($site, $request->user())
        );

        return back()->with('status', (bool) $data['enabled'] ? 'Direct Demand master enabled.' : 'Direct Demand master paused and affected configurations republished.');
    }

    public function updateNetwork(Request $request, DemandNetwork $demandNetwork, AuditRecorder $audit, SiteConfigPublisher $publisher): RedirectResponse
    {
        $data = $request->validate([
            'supports_direct_js' => ['required', 'boolean'],
            'supported_formats' => ['nullable', 'array'],
            'supported_formats.*' => ['string', Rule::in(['DISPLAY', 'NATIVE', 'VIDEO', 'OUTSTREAM'])],
            'integration_modes' => ['nullable', 'array'],
            'integration_modes.*' => [Rule::enum(DemandIntegrationMode::class)],
            'script_origins' => ['nullable', 'array', 'max:20'],
            'script_origins.*' => ['url:https', 'max:500'],
            'operational_health' => ['nullable', Rule::in(['HEALTHY', 'DEGRADED', 'FAILED', 'UNKNOWN'])],
        ]);
        $origins = collect($data['script_origins'] ?? [])->map(fn ($value) => strtolower(rtrim((string) $value, '/')))->unique()->values();
        foreach ($origins as $origin) {
            $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
            if ($host === 'app.horusmedia.net' || str_ends_with($host, '.app.horusmedia.net')) {
                throw ValidationException::withMessages(['script_origins' => 'Provider script origins cannot use the Horus control-plane origin.']);
            }
        }
        $before = $demandNetwork->only(['supports_direct_js', 'script_origins', 'capabilities', 'metadata']);
        $capabilities = array_replace((array) $demandNetwork->capabilities, [
            'supported_formats' => array_values(array_unique($data['supported_formats'] ?? [])),
            'integration_modes' => array_values(array_unique($data['integration_modes'] ?? [])),
        ]);
        $metadata = array_replace((array) $demandNetwork->metadata, ['operational_health' => $data['operational_health'] ?? 'UNKNOWN']);
        $demandNetwork->update([
            'supports_direct_js' => (bool) $data['supports_direct_js'],
            'script_origins' => $origins->all(),
            'capabilities' => $capabilities,
            'metadata' => $metadata,
        ]);
        $audit->record('demand.network.updated', $request->user()->organization_id, $request->user(), $demandNetwork,
            $before, $demandNetwork->only(array_keys($before)));
        $this->publishNetworkSites($demandNetwork, $request, $publisher);

        return back()->with('status', 'Direct Demand network settings updated and affected configurations published.');
    }

    public function toggleNetworkRuntime(Request $request, DemandNetwork $demandNetwork, PlatformControlService $controls, SiteConfigPublisher $publisher): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:10000'],
        ]);
        $controls->set('DEMAND_NETWORK', $demandNetwork->id, 'DIRECT_JS', ! (bool) $data['enabled'], $data['reason'], $request->user());
        $this->publishNetworkSites($demandNetwork, $request, $publisher);

        return back()->with('status', 'Network Direct Demand runtime control updated.');
    }

    public function toggleAccount(Request $request, DemandAccount $demandAccount, DemandAccountService $service, SiteConfigPublisher $publisher): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $service->update($demandAccount, ['is_enabled' => (bool) $data['enabled']], $request->user());
        $this->publishAccountSites($demandAccount, $request, $publisher);

        return back()->with('status', 'Demand account delivery toggle updated.');
    }

    public function tagPreview(Request $request, DemandAccount $demandAccount, DemandConnectorManager $connectors): View
    {
        $data = $request->validate(['tag' => ['required', 'string', 'max:100000']]);
        $review = $connectors->for($demandAccount->loadMissing('network'))->parseDirectTag($data['tag']);

        return view('admin.demand.tag-preview', ['account' => $demandAccount, 'review' => $review]);
    }

    public function storeAccount(Request $request, DemandAccountService $service): RedirectResponse
    {
        $data = $request->validate([
            'demand_network_id' => ['required', 'ulid', 'exists:demand_networks,id'],
            'organization_id' => ['nullable', 'ulid', 'exists:organizations,id'],
            'publisher_id' => ['nullable', 'ulid', 'exists:publishers,id'],
            'partner_organization_id' => ['nullable', 'ulid', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
            'scope' => ['required', Rule::enum(DemandAccountScope::class)],
            'integration_mode' => ['required', Rule::enum(DemandIntegrationMode::class)],
            'approval_status' => ['nullable', Rule::enum(DemandApprovalStatus::class)],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'revenue_share_percent' => ['required', 'numeric', 'between:0,100'],
            'fallback_priority' => ['required', 'integer', 'between:0,10000'],
            'account_identifier' => ['nullable', 'string', 'max:255'],
            'configuration_json' => ['nullable', 'json'],
            'reporting_method' => ['nullable', Rule::in(['API', 'CSV'])],
            'default_render_timeout_ms' => ['nullable', 'integer', 'between:500,10000'],
            'approved_script_origins' => ['nullable', 'array', 'max:20'],
            'approved_script_origins.*' => ['url:https', 'max:500'],
        ]);
        $data['configuration'] = $this->accountConfiguration($data, $this->json($data['configuration_json'] ?? null));
        unset($data['configuration_json'], $data['reporting_method'], $data['default_render_timeout_ms'], $data['approved_script_origins']);

        $account = $service->create($data, $request->user());

        return redirect()->route('admin.demand.index')->with('status', "Demand account {$account->name} created.");
    }

    public function updateAccount(Request $request, DemandAccount $demandAccount, DemandAccountService $service): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scope' => ['required', Rule::enum(DemandAccountScope::class)],
            'integration_mode' => ['required', Rule::enum(DemandIntegrationMode::class)],
            'approval_status' => ['required', Rule::enum(DemandApprovalStatus::class)],
            'is_enabled' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
            'revenue_share_percent' => ['required', 'numeric', 'between:0,100'],
            'fallback_priority' => ['required', 'integer', 'between:0,10000'],
            'account_identifier' => ['nullable', 'string', 'max:255'],
            'configuration_json' => ['nullable', 'json'],
            'reporting_method' => ['nullable', Rule::in(['API', 'CSV'])],
            'default_render_timeout_ms' => ['nullable', 'integer', 'between:500,10000'],
            'approved_script_origins' => ['nullable', 'array', 'max:20'],
            'approved_script_origins.*' => ['url:https', 'max:500'],
        ]);
        $data['configuration'] = $this->accountConfiguration($data, $this->json($data['configuration_json'] ?? null));
        unset($data['configuration_json'], $data['reporting_method'], $data['default_render_timeout_ms'], $data['approved_script_origins']);
        $service->update($demandAccount, $data, $request->user());
        $this->publishAccountSites($demandAccount, $request, app(SiteConfigPublisher::class));

        return back()->with('status', 'Demand account updated and affected static configurations published.');
    }

    public function updateFinancialSource(
        Request $request,
        DemandAccount $demandAccount,
        MonetizationFinancialBindingService $bindings,
    ): RedirectResponse {
        $data = $request->validate([
            'report_source_id' => ['required', 'ulid', 'exists:report_sources,id'],
            'reporting_method' => ['required', Rule::enum(FinancialReportingMethod::class)],
            'currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'timezone'],
            'is_enabled' => ['required', 'boolean'],
            'configuration_json' => ['nullable', 'json'],
        ]);
        $bindings->bind(
            $demandAccount,
            ReportSource::query()->findOrFail($data['report_source_id']),
            FinancialReportingMethod::from($data['reporting_method']),
            $data['currency'],
            $data['timezone'],
            $request->user(),
            isset($data['configuration_json']) ? (array) json_decode($data['configuration_json'], true, 512, JSON_THROW_ON_ERROR) : [],
            (bool) $data['is_enabled'],
        );

        return back()->with('status', 'Canonical financial source binding updated.');
    }

    public function storeCredential(Request $request, DemandAccount $demandAccount, DemandAccountService $service): RedirectResponse
    {
        $data = $request->validate([
            'credential_key' => ['required', 'alpha_dash', 'max:80'],
            'reference' => ['required', 'string', 'max:1000'],
            'hint' => ['nullable', 'string', 'max:255'],
            'capability' => ['nullable', 'string', 'max:32'],
            'expires_at' => ['nullable', 'date'],
        ]);
        $service->upsertCredential($demandAccount, $data, $request->user());

        return back()->with('status', 'Encrypted credential reference saved.');
    }

    public function testAccount(Request $request, DemandAccount $demandAccount, DemandAccountService $service): RedirectResponse
    {
        $result = $service->test($demandAccount, $request->user(), $request->boolean('dry_run'));

        return back()->with($result->success ? 'status' : 'error', $result->success ? 'Demand account test succeeded.' : $result->errorMessage);
    }

    public function reviewAccount(
        Request $request,
        DemandAccount $demandAccount,
        DemandAccountService $service,
        SiteConfigPublisher $publisher,
    ): RedirectResponse {
        $data = $request->validate([
            'approval_status' => ['required', Rule::enum(DemandApprovalStatus::class)],
            'reason' => ['nullable', 'string', 'max:10000'],
        ]);
        $service->reviewAccount($demandAccount, DemandApprovalStatus::from($data['approval_status']), $request->user(), $data['reason'] ?? null);
        $this->publishAccountSites($demandAccount, $request, $publisher);

        return back()->with('status', 'Demand account approval status updated and affected configurations published.');
    }

    public function toggleNetwork(Request $request, DemandNetwork $demandNetwork, SiteConfigPublisher $publisher, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate(['is_enabled' => ['required', 'boolean']]);
        $before = (bool) $demandNetwork->is_enabled;
        $demandNetwork->update(['is_enabled' => (bool) $data['is_enabled']]);
        $audit->record('demand.network.enabled_changed', $request->user()->organization_id, $request->user(), $demandNetwork,
            ['is_enabled' => $before], ['is_enabled' => (bool) $data['is_enabled']]);

        $sites = Site::withoutGlobalScopes()
            ->whereHas('demandSites.account', fn ($query) => $query->where('demand_network_id', $demandNetwork->id))
            ->get();
        foreach ($sites as $site) {
            $publisher->publishActiveProduction($site, $request->user());
        }

        return back()->with('status', 'Connector status updated and future configurations republished.');
    }

    public function assignSite(Request $request, Site $site, DemandAccount $demandAccount, DemandAccountService $service, SiteConfigPublisher $publisher): RedirectResponse
    {
        $data = $this->siteData($request);
        $service->assignSite($demandAccount, $site, $data, $request->user());
        $site->update(['native_demand_enabled' => true]);
        $publisher->publishActiveProduction($site, $request->user());

        return back()->with('status', 'Demand account assigned. Active websites queue production automatically; inactive websites publish on activation.');
    }

    public function updateSite(Request $request, Site $site, DemandSite $demandSite, DemandAccountService $service, SiteConfigPublisher $publisher): RedirectResponse
    {
        abort_unless($demandSite->site_id === $site->id, 404);
        $data = $this->siteData($request);
        $demandSite->update($data + ['updated_by' => $request->user()->id]);
        $service->reviewSite($demandSite, DemandApprovalStatus::from($data['approval_status']), $request->user());
        $publisher->publishActiveProduction($site, $request->user());

        return back()->with('status', 'Website demand mapping updated and queued automatically when active.');
    }

    public function toggleSiteMapping(Request $request, Site $site, DemandSite $demandSite, DemandAccountService $service, SiteConfigPublisher $publisher): RedirectResponse
    {
        abort_unless($demandSite->site_id === $site->id, 404);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $service->setSiteEnabled($demandSite, (bool) $data['enabled'], $request->user());
        $publisher->publishActiveProduction($site, $request->user());

        return back()->with('status', 'Website mapping delivery toggle updated.');
    }

    public function syncSite(Request $request, Site $site, DemandSite $demandSite, DemandAccountService $service): RedirectResponse
    {
        abort_unless($demandSite->site_id === $site->id, 404);
        $result = $service->syncSite($demandSite, $request->user(), $request->boolean('dry_run', true));

        return back()->with($result->success ? 'status' : 'error', $result->success ? 'Website mapping synchronized.' : $result->errorMessage);
    }

    public function refreshSiteStatus(Request $request, Site $site, DemandSite $demandSite, DemandAccountService $service): RedirectResponse
    {
        abort_unless($demandSite->site_id === $site->id, 404);
        $result = $service->refreshSiteStatus($demandSite, $request->user());

        return back()->with($result->success ? 'status' : 'error', $result->success ? 'Provider website status synchronized.' : $result->errorMessage);
    }

    public function syncAdsTxt(Request $request, Site $site, DemandSite $demandSite, DemandReportService $reports): RedirectResponse
    {
        abort_unless($demandSite->site_id === $site->id, 404);
        $count = $reports->syncAdsTxt($demandSite->account, $demandSite, $request->user());

        return back()->with('status', "{$count} ads.txt record(s) synchronized.");
    }

    public function assignPlacement(Request $request, Site $site, DemandSite $demandSite, Placement $placement, DemandAccountService $service, SiteConfigPublisher $publisher): RedirectResponse
    {
        abort_unless($demandSite->site_id === $site->id && $placement->site_id === $site->id, 404);
        $data = $this->placementData($request);
        $this->assertDirectPlacementSupported($demandSite, $placement, $data['integration_mode'] ?? null);
        $service->assignPlacement($demandSite, $placement, $data, $request->user());
        $publisher->publishActiveProduction($site, $request->user());

        return back()->with('status', 'Placement mapping saved and queued automatically when the website is active.');
    }

    public function updatePlacement(Request $request, Site $site, DemandPlacement $demandPlacement, DemandAccountService $service, SiteConfigPublisher $publisher): RedirectResponse
    {
        abort_unless($demandPlacement->demandSite->site_id === $site->id, 404);
        $data = $this->placementData($request);
        $this->assertDirectPlacementSupported($demandPlacement->demandSite, $demandPlacement->placement, $data['integration_mode'] ?? null);
        $demandPlacement->update($data + ['updated_by' => $request->user()->id]);
        $service->reviewPlacement($demandPlacement, DemandApprovalStatus::from($data['approval_status']), $request->user());
        $publisher->publishActiveProduction($site, $request->user());

        return back()->with('status', 'Demand placement updated and queued automatically when the website is active.');
    }

    public function togglePlacementMapping(Request $request, Site $site, DemandPlacement $demandPlacement, DemandAccountService $service, SiteConfigPublisher $publisher): RedirectResponse
    {
        abort_unless($demandPlacement->demandSite->site_id === $site->id, 404);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $service->setPlacementMappingEnabled($demandPlacement, (bool) $data['enabled'], $request->user());
        $publisher->publishActiveProduction($site, $request->user());

        return back()->with('status', 'Placement mapping delivery toggle updated.');
    }

    public function storeWidget(Request $request, Site $site, DemandPlacement $demandPlacement, DemandAccountService $service, SiteConfigPublisher $publisher, DemandConnectorManager $connectors): RedirectResponse
    {
        abort_unless($demandPlacement->demandSite->site_id === $site->id, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'remote_widget_id' => ['nullable', 'string', 'max:128'],
            'widget_code' => ['nullable', 'string', 'max:255'],
            'integration_mode' => ['nullable', Rule::enum(DemandIntegrationMode::class)],
            'direct_tag_template' => ['nullable', 'string', 'max:100000'],
            'gam_creative_template' => ['nullable', 'string', 'max:100000'],
            'approval_status' => ['required', Rule::enum(DemandApprovalStatus::class)],
            'is_enabled' => ['required', 'boolean'],
            'tag_review_approved' => ['nullable', 'boolean'],
            'configuration_json' => ['nullable', 'json'],
        ]);
        $data['configuration'] = $this->json($data['configuration_json'] ?? null);
        unset($data['configuration_json']);
        $requestedStatus = DemandApprovalStatus::from($data['approval_status']);
        $tag = trim((string) ($data['direct_tag_template'] ?? ''));
        $modeValue = $data['integration_mode'] ?? $demandPlacement->integration_mode ?? $demandPlacement->demandSite->integration_mode ?? $demandPlacement->demandSite->account->integration_mode;
        $mode = $modeValue instanceof DemandIntegrationMode ? $modeValue : DemandIntegrationMode::from((string) $modeValue);

        if ($tag !== '' && $mode === DemandIntegrationMode::DirectJs) {
            if ($requestedStatus === DemandApprovalStatus::Approved && ! $request->boolean('tag_review_approved')) {
                throw ValidationException::withMessages(['tag_review_approved' => 'Explicit tag review approval is required before production publication.']);
            }
            $connector = $connectors->for($demandPlacement->demandSite->account->loadMissing('network'));
            $review = $connector->parseDirectTag($tag);
            $isCustom = $demandPlacement->demandSite->account->network->code->value === 'CUSTOM_THIRD_PARTY_TAG';
            if (! $isCustom && ! (bool) ($review['safe'] ?? false)) {
                throw ValidationException::withMessages(['direct_tag_template' => $review['securityWarnings'] ?: ['The tag cannot be represented by a safe structured Direct Demand recipe.']]);
            }
            if ($isCustom) {
                $warnings = collect((array) ($review['securityWarnings'] ?? []));
                if ($warnings->contains(fn ($warning) => str_contains(strtolower((string) $warning), 'credential') || str_contains(strtolower((string) $warning), 'javascript:'))) {
                    throw ValidationException::withMessages(['direct_tag_template' => $warnings->all()]);
                }
                if (empty($data['configuration']['isolation_allowed_origins'])) {
                    throw ValidationException::withMessages(['configuration_json' => 'Custom Third Party tags require explicit isolation_allowed_origins for iframe CSP.']);
                }
            } else {
                $data['configuration']['direct_recipe'] = $review['recipe'];
            }
        }
        unset($data['tag_review_approved']);

        DB::transaction(function () use ($service, $demandPlacement, $data, $request, $connectors, $tag, $mode): void {
            $widget = $service->upsertWidget($demandPlacement, $data, $request->user());
            if ($tag !== '' && $mode === DemandIntegrationMode::DirectJs && $data['approval_status'] === DemandApprovalStatus::Approved->value) {
                // Final runtime validation happens inside the same transaction so
                // an unsafe/unsupported recipe cannot leave an approved widget behind.
                $connectors->for($demandPlacement->demandSite->account->loadMissing('network'))->generateDirectTag($demandPlacement->fresh(['widgets', 'placement.sizes', 'demandSite.account.network']));
            }
        });
        $publisher->publishActiveProduction($site, $request->user());

        return back()->with('status', 'Direct Demand tag/widget saved after safety review and the active static configuration was republished.');
    }

    public function syncPlacement(Request $request, Site $site, DemandPlacement $demandPlacement, DemandAccountService $service): RedirectResponse
    {
        abort_unless($demandPlacement->demandSite->site_id === $site->id, 404);
        $result = $service->syncPlacement($demandPlacement, $request->user(), $request->boolean('dry_run', true));

        return back()->with($result->success ? 'status' : 'error', $result->success ? 'Demand placement synchronized.' : $result->errorMessage);
    }

    public function placementStatus(Request $request, Site $site, DemandPlacement $demandPlacement, DemandAccountService $service, DemandGamDeploymentService $gam, SiteConfigPublisher $publisher): RedirectResponse
    {
        abort_unless($demandPlacement->demandSite->site_id === $site->id, 404);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $mode = $demandPlacement->integration_mode
            ?? $demandPlacement->demandSite->integration_mode
            ?? $demandPlacement->demandSite->account->integration_mode;

        if (in_array($mode, [DemandIntegrationMode::GamThirdPartyCreative, DemandIntegrationMode::GamLineItem], true)) {
            $data['enabled'] ? $gam->resume($demandPlacement, $request->user()) : $gam->pause($demandPlacement, $request->user());
        } else {
            $result = $service->setPlacementEnabled($demandPlacement, (bool) $data['enabled'], $request->user());
            if (! $result->success) {
                return back()->with('error', $result->errorMessage);
            }
        }

        $publisher->publishActiveProduction($site, $request->user());

        return back()->with('status', 'Placement delivery status synchronized and queued automatically when the website is active.');
    }

    public function deployGam(Request $request, Site $site, DemandSite $demandSite, DemandGamDeploymentService $gam): RedirectResponse
    {
        abort_unless($demandSite->site_id === $site->id, 404);
        $data = $request->validate([
            'dry_run' => ['required', 'boolean'],
            'confirm_external_writes' => ['nullable', 'accepted'],
        ]);
        $result = $gam->deploy($demandSite, $request->user(), (bool) $data['dry_run'], $request->boolean('confirm_external_writes'));

        return back()->with($result['success'] ? 'status' : 'error', $result['success'] ? 'Native GAM deployment completed.' : ($result['error'] ?? implode(' ', $result['plan']['issues'] ?? [])));
    }

    public function runApiReport(Request $request, DemandAccount $demandAccount, DemandReportService $reports): RedirectResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $import = $reports->runApi($demandAccount, CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to']), $request->user());

        return back()->with($import->status->value === 'COMPLETED' ? 'status' : 'error', $import->status->value === 'COMPLETED' ? 'API report imported.' : $import->error_message);
    }

    public function importCsv(Request $request, DemandAccount $demandAccount, DemandReportService $reports): RedirectResponse
    {
        $data = $request->validate([
            'report' => ['required', 'file', 'mimes:csv,txt'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
        $import = $reports->importCsv($demandAccount, $data['report'], CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to']), $request->user());

        return back()->with('status', "{$import->row_count} aggregated report row(s) imported.");
    }

    private function siteData(Request $request): array
    {
        $data = $request->validate([
            'approval_status' => ['required', Rule::enum(DemandApprovalStatus::class)],
            'is_enabled' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
            'integration_mode' => ['nullable', Rule::enum(DemandIntegrationMode::class)],
            'revenue_share_percent' => ['nullable', 'numeric', 'between:0,100'],
            'fallback_priority' => ['nullable', 'integer', 'between:0,10000'],
            'remote_site_id' => ['nullable', 'string', 'max:128'],
            'configuration_json' => ['nullable', 'json'],
        ]);
        $data['configuration'] = $this->json($data['configuration_json'] ?? null);
        unset($data['configuration_json']);

        return $data;
    }

    private function placementData(Request $request): array
    {
        $data = $request->validate([
            'approval_status' => ['required', Rule::enum(DemandApprovalStatus::class)],
            'is_enabled' => ['required', 'boolean'],
            'integration_mode' => ['nullable', Rule::enum(DemandIntegrationMode::class)],
            'fallback_priority' => ['nullable', 'integer', 'between:0,10000'],
            'remote_placement_id' => ['nullable', 'string', 'max:128'],
            'placement_code' => ['nullable', 'string', 'max:255'],
            'configuration_json' => ['nullable', 'json'],
        ]);
        $data['configuration'] = $this->json($data['configuration_json'] ?? null);
        unset($data['configuration_json']);

        return $data;
    }

    private function json(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw ValidationException::withMessages(['configuration_json' => $exception->getMessage()]);
        }
    }

    private function assertDirectPlacementSupported(DemandSite $demandSite, Placement $placement, mixed $modeOverride): void
    {
        $demandSite->loadMissing('account.network');
        $modeValue = $modeOverride ?: $demandSite->integration_mode ?: $demandSite->account->integration_mode;
        $mode = $modeValue instanceof DemandIntegrationMode ? $modeValue : DemandIntegrationMode::from((string) $modeValue);
        if ($mode !== DemandIntegrationMode::DirectJs) {
            return;
        }

        $network = $demandSite->account->network;
        if (! $network->supports_direct_js) {
            throw ValidationException::withMessages(['integration_mode' => 'This network is not approved for Direct JS delivery.']);
        }
        $formats = collect((array) data_get($network->capabilities, 'supported_formats', []))->map(fn ($value) => strtoupper((string) $value));
        $placementFormat = strtoupper($placement->type->value);
        if ($formats->isNotEmpty() && ! $formats->contains($placementFormat)) {
            throw ValidationException::withMessages(['placement' => "{$network->name} is not approved for {$placementFormat} Direct Demand placements."]);
        }

        $allowedSizes = collect((array) data_get($network->capabilities, 'supported_sizes', []))
            ->filter(fn ($size) => is_array($size) && count($size) === 2)
            ->map(fn ($size) => ((int) $size[0]).'x'.((int) $size[1]));
        if ($allowedSizes->isEmpty()) {
            return;
        }
        $placement->loadMissing('sizes');
        $hasCompatibleSize = $placement->sizes->where('is_active', true)
            ->contains(fn ($size) => $size->width && $size->height && $allowedSizes->contains(((int) $size->width).'x'.((int) $size->height)));
        if (! $hasCompatibleSize) {
            throw ValidationException::withMessages(['placement' => 'The Horus placement has no size approved by this Direct Demand network.']);
        }
    }

    private function accountConfiguration(array $data, array $configuration): array
    {
        if (! empty($data['reporting_method'])) {
            $configuration['reporting_method'] = $data['reporting_method'];
        }
        if (! empty($data['default_render_timeout_ms'])) {
            $configuration['render_timeout_ms'] = (int) $data['default_render_timeout_ms'];
        }
        if (array_key_exists('approved_script_origins', $data)) {
            $configuration['allowed_script_origins'] = collect((array) $data['approved_script_origins'])
                ->map(fn ($value) => strtolower(rtrim((string) $value, '/')))->unique()->values()->all();
        }

        return $configuration;
    }

    private function publishNetworkSites(DemandNetwork $network, Request $request, SiteConfigPublisher $publisher): void
    {
        Site::withoutGlobalScopes()
            ->whereHas('demandSites.account', fn ($query) => $query->where('demand_network_id', $network->id))
            ->get()
            ->each(fn (Site $site) => $publisher->publishActiveProduction($site, $request->user()));
    }

    private function publishAccountSites(DemandAccount $account, Request $request, SiteConfigPublisher $publisher): void
    {
        $account->sites()->with('site')->get()->each(
            fn ($mapping) => $publisher->publishActiveProduction($mapping->site, $request->user())
        );
    }
}
