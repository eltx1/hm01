from pathlib import Path
import re


def replace_once(path, old, new):
    p = Path(path)
    text = p.read_text()
    if old not in text:
        raise SystemExit(f'marker missing in {path}: {old[:120]!r}')
    p.write_text(text.replace(old, new, 1))

# Navigation terminology
replace_once('app/Services/ControlPlane/ControlPlaneNavigation.php', "'Native demand', 'admin.demand.index'", "'Direct Demand', 'admin.demand.index'")

# Permission description terminology only; permission keys stay compatible.
p = Path('database/seeders/IdentityAccessSeeder.php')
text = p.read_text().replace("'demand.view' => ['View native and alternative demand networks', 'demand']", "'demand.view' => ['View Direct Demand networks and reporting', 'demand']")
p.write_text(text)

# Demand routes: Horus-only admin plane + genuinely new controls.
p = Path('app/Providers/DemandServiceProvider.php')
text = p.read_text()
text = text.replace("Route::middleware(['web', 'auth', 'active', 'verified', 'admin.2fa'])->group", "Route::middleware(['web', 'auth', 'active', 'verified', 'admin.2fa', 'horus'])->group", 1)
text = text.replace(
"""            Route::get('/admin/demand', [DemandNetworkController::class, 'index'])
                ->middleware('permission:demand.view')->name('admin.demand.index');
""",
"""            Route::get('/admin/demand', [DemandNetworkController::class, 'index'])
                ->middleware('permission:demand.view')->name('admin.demand.index');
            Route::patch('/admin/demand/master', [DemandNetworkController::class, 'toggleMaster'])
                ->middleware('permission:demand.manage')->name('admin.demand.master');
""", 1)
text = text.replace(
"""            Route::put('/admin/demand/accounts/{demandAccount}', [DemandNetworkController::class, 'updateAccount'])
                ->middleware('permission:demand.manage')->name('admin.demand.accounts.update');
""",
"""            Route::put('/admin/demand/accounts/{demandAccount}', [DemandNetworkController::class, 'updateAccount'])
                ->middleware('permission:demand.manage')->name('admin.demand.accounts.update');
            Route::patch('/admin/demand/accounts/{demandAccount}/enabled', [DemandNetworkController::class, 'toggleAccount'])
                ->middleware('permission:demand.manage')->name('admin.demand.accounts.enabled');
            Route::post('/admin/demand/accounts/{demandAccount}/tags/preview', [DemandNetworkController::class, 'tagPreview'])
                ->middleware('permission:demand.manage')->name('admin.demand.tags.preview');
""", 1)
text = text.replace(
"""            Route::patch('/admin/demand/networks/{demandNetwork}', [DemandNetworkController::class, 'toggleNetwork'])
                ->middleware('permission:demand.manage')->name('admin.demand.networks.toggle');
""",
"""            Route::patch('/admin/demand/networks/{demandNetwork}', [DemandNetworkController::class, 'toggleNetwork'])
                ->middleware('permission:demand.manage')->name('admin.demand.networks.toggle');
            Route::put('/admin/demand/networks/{demandNetwork}/settings', [DemandNetworkController::class, 'updateNetwork'])
                ->middleware('permission:demand.manage')->name('admin.demand.networks.settings');
            Route::patch('/admin/demand/networks/{demandNetwork}/direct-js', [DemandNetworkController::class, 'toggleNetworkRuntime'])
                ->middleware('permission:demand.manage')->name('admin.demand.networks.direct-js');
""", 1)
text = text.replace(
"""            Route::put('/admin/sites/{site}/demand/mappings/{demandSite}', [DemandNetworkController::class, 'updateSite'])
                ->middleware('permission:demand.manage')->name('admin.sites.demand.mappings.update');
""",
"""            Route::put('/admin/sites/{site}/demand/mappings/{demandSite}', [DemandNetworkController::class, 'updateSite'])
                ->middleware('permission:demand.manage')->name('admin.sites.demand.mappings.update');
            Route::patch('/admin/sites/{site}/demand/mappings/{demandSite}/enabled', [DemandNetworkController::class, 'toggleSiteMapping'])
                ->middleware('permission:demand.manage')->name('admin.sites.demand.mappings.enabled');
""", 1)
text = text.replace(
"""            Route::put('/admin/sites/{site}/demand/placements/{demandPlacement}', [DemandNetworkController::class, 'updatePlacement'])
                ->middleware('permission:demand.manage')->name('admin.sites.demand.placements.update');
""",
"""            Route::put('/admin/sites/{site}/demand/placements/{demandPlacement}', [DemandNetworkController::class, 'updatePlacement'])
                ->middleware('permission:demand.manage')->name('admin.sites.demand.placements.update');
            Route::patch('/admin/sites/{site}/demand/placements/{demandPlacement}/enabled', [DemandNetworkController::class, 'togglePlacementMapping'])
                ->middleware('permission:demand.manage')->name('admin.sites.demand.placements.enabled');
""", 1)
p.write_text(text)

# DemandAccountService: audited local enable toggles, separate from remote-provider pause API.
p = Path('app/Services/Demand/DemandAccountService.php')
text = p.read_text()
marker = "    public function setPlacementEnabled(DemandPlacement $placement, bool $enabled, User $actor): DemandResult\n"
methods = '''    public function setSiteEnabled(DemandSite $site, bool $enabled, User $actor): DemandSite
    {
        $before = (bool) $site->is_enabled;
        $site->update(['is_enabled' => $enabled, 'updated_by' => $actor->id]);
        $this->audit->record('demand.site.enabled_changed', $site->organization_id, $actor, $site,
            ['is_enabled' => $before], ['is_enabled' => $enabled]);

        return $site->refresh();
    }

    public function setPlacementMappingEnabled(DemandPlacement $placement, bool $enabled, User $actor): DemandPlacement
    {
        $before = (bool) $placement->is_enabled;
        $placement->update(['is_enabled' => $enabled, 'updated_by' => $actor->id]);
        $this->audit->record('demand.placement.enabled_changed', $placement->organization_id, $actor, $placement,
            ['is_enabled' => $before], ['is_enabled' => $enabled]);

        return $placement->refresh();
    }

'''
if marker not in text: raise SystemExit('DemandAccountService insertion marker missing')
text = text.replace(marker, methods + marker, 1)
p.write_text(text)

# Controller: add imports and admin controls/tag review.
p = Path('app/Http/Controllers/Admin/DemandNetworkController.php')
text = p.read_text()
text = text.replace('use App\\Services\\Demand\\DemandGamDeploymentService;\n', 'use App\\Services\\Demand\\DemandGamDeploymentService;\nuse App\\Services\\Demand\\DemandConnectorManager;\n')
text = text.replace('use App\\Services\\Inventory\\SiteConfigPublisher;\n', 'use App\\Services\\Inventory\\SiteConfigPublisher;\nuse App\\Services\\Operations\\PlatformControlService;\nuse App\\Services\\Audit\\AuditRecorder;\nuse Illuminate\\Support\\Facades\\DB;\n')
text = text.replace("            'summaries' => $accounts->getCollection()->mapWithKeys(fn ($account) => [$account->id => $reports->summary($account)]),\n", "            'summaries' => $accounts->getCollection()->mapWithKeys(fn ($account) => [$account->id => $reports->summary($account)]),\n            'directDemandMasterEnabled' => ! app(PlatformControlService::class)->disabled('PLATFORM', null, 'DIRECT_JS'),\n")
text = text.replace("return back()->with('status', $site->native_demand_enabled ? 'Native demand enabled for this website.' : 'Native demand disabled and removed from the current configuration.');", "return back()->with('status', $site->native_demand_enabled ? 'Direct Demand enabled for this website.' : 'Direct Demand disabled and removed from the current configuration.');")

# Add controller methods before storeAccount.
marker = '    public function storeAccount(Request $request, DemandAccountService $service): RedirectResponse\n'
methods = '''    public function toggleMaster(Request $request, PlatformControlService $controls, SiteConfigPublisher $publisher): RedirectResponse
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

'''
if marker not in text: raise SystemExit('controller storeAccount marker missing')
text = text.replace(marker, methods + marker, 1)

# Merge easy account settings into existing config JSON for create/update.
text = text.replace("            'configuration_json' => ['nullable', 'json'],\n        ]);\n        $data['configuration'] = $this->json($data['configuration_json'] ?? null);", "            'configuration_json' => ['nullable', 'json'],\n            'reporting_method' => ['nullable', Rule::in(['API', 'CSV'])],\n            'default_render_timeout_ms' => ['nullable', 'integer', 'between:500,10000'],\n            'approved_script_origins' => ['nullable', 'array', 'max:20'],\n            'approved_script_origins.*' => ['url:https', 'max:500'],\n        ]);\n        $data['configuration'] = $this->accountConfiguration($data, $this->json($data['configuration_json'] ?? null));", 2)
text = text.replace("        unset($data['configuration_json']);", "        unset($data['configuration_json'], $data['reporting_method'], $data['default_render_timeout_ms'], $data['approved_script_origins']);", 2)

# Dedicated audited local mapping toggles before syncSite.
marker = '    public function syncSite(Request $request, Site $site, DemandSite $demandSite, DemandAccountService $service): RedirectResponse\n'
methods = '''    public function toggleSiteMapping(Request $request, Site $site, DemandSite $demandSite, DemandAccountService $service, SiteConfigPublisher $publisher): RedirectResponse
    {
        abort_unless($demandSite->site_id === $site->id, 404);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $service->setSiteEnabled($demandSite, (bool) $data['enabled'], $request->user());
        $publisher->publishActiveProduction($site, $request->user());

        return back()->with('status', 'Website mapping delivery toggle updated.');
    }

'''
text = text.replace(marker, methods + marker, 1)
marker = '    public function storeWidget(Request $request, Site $site, DemandPlacement $demandPlacement, DemandAccountService $service, SiteConfigPublisher $publisher): RedirectResponse\n'
methods = '''    public function togglePlacementMapping(Request $request, Site $site, DemandPlacement $demandPlacement, DemandAccountService $service, SiteConfigPublisher $publisher): RedirectResponse
    {
        abort_unless($demandPlacement->demandSite->site_id === $site->id, 404);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $service->setPlacementMappingEnabled($demandPlacement, (bool) $data['enabled'], $request->user());
        $publisher->publishActiveProduction($site, $request->user());

        return back()->with('status', 'Placement mapping delivery toggle updated.');
    }

'''
text = text.replace(marker, methods + marker, 1)

# Replace storeWidget with review-aware transaction.
pattern = re.compile(r"    public function storeWidget\(Request \$request, Site \$site, DemandPlacement \$demandPlacement, DemandAccountService \$service, SiteConfigPublisher \$publisher\): RedirectResponse\n    \{.*?\n    \}\n\n    public function syncPlacement", re.S)
replacement = '''    public function storeWidget(Request $request, Site $site, DemandPlacement $demandPlacement, DemandAccountService $service, SiteConfigPublisher $publisher, DemandConnectorManager $connectors): RedirectResponse
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
        $mode = $data['integration_mode'] ?? $demandPlacement->integration_mode ?? $demandPlacement->demandSite->integration_mode ?? $demandPlacement->demandSite->account->integration_mode;

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

    public function syncPlacement'''
text, count = pattern.subn(replacement, text, count=1)
if count != 1: raise SystemExit('storeWidget replacement failed')

# Helpers at tail.
marker = '    private function publishAccountSites(DemandAccount $account, Request $request, SiteConfigPublisher $publisher): void\n'
helpers = '''    private function accountConfiguration(array $data, array $configuration): array
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

'''
text = text.replace(marker, helpers + marker, 1)
p.write_text(text)

# Publisher readiness white-label terminology.
p = Path('app/Services/Monetization/SiteMonetizationReadinessService.php')
text = p.read_text()
text = text.replace("'Native / Alternative Demand'", "'Direct Monetization'")
text = text.replace('Native / alternative demand', 'Direct monetization')
text = text.replace('Native demand', 'Direct monetization')
p.write_text(text)

# Publisher site screen: no admin demand management link, white-label only.
p = Path('resources/views/publisher/sites/show.blade.php')
text = p.read_text()
text = text.replace('<div class="workspace-heading"><div><p class="eyebrow">Optional demand</p><h2>Native demand</h2></div>@if(auth()->user()->hasPermission(\'demand.view\'))<a class="section-anchor" href="{{ route(\'admin.sites.demand.index\', $site) }}">Manage native demand</a>@endif</div>', '<div class="workspace-heading"><div><p class="eyebrow">Monetization</p><h2>Direct Monetization</h2></div></div>')
p.write_text(text)

Path('scripts/task18_materialize.py').unlink(missing_ok=True)
Path('.github/workflows/task18-materialize.yml').unlink(missing_ok=True)
