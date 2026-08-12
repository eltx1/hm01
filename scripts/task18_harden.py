from pathlib import Path
import re


def replace_once(path, old, new):
    p = Path(path)
    text = p.read_text()
    if old not in text:
        raise SystemExit(f'marker missing in {path}: {old[:100]!r}')
    p.write_text(text.replace(old, new, 1))

# Controller audit/lifecycle/capability hardening.
p = Path('app/Http/Controllers/Admin/DemandNetworkController.php')
text = p.read_text()
text = text.replace(
"""    public function toggleSiteNative(Request $request, Site $site, SiteConfigPublisher $publisher): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $site->update(['native_demand_enabled' => (bool) $data['enabled']]);
""",
"""    public function toggleSiteNative(Request $request, Site $site, SiteConfigPublisher $publisher, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $before = (bool) $site->native_demand_enabled;
        $site->update(['native_demand_enabled' => (bool) $data['enabled']]);
        $audit->record('demand.site.direct_demand_enabled_changed', $site->organization_id, $request->user(), $site,
            ['native_demand_enabled' => $before], ['native_demand_enabled' => (bool) $data['enabled']]);
""", 1)
text = text.replace(
"""    public function toggleNetwork(Request $request, DemandNetwork $demandNetwork, SiteConfigPublisher $publisher): RedirectResponse
    {
        $data = $request->validate(['is_enabled' => ['required', 'boolean']]);
        $demandNetwork->update(['is_enabled' => (bool) $data['is_enabled']]);
""",
"""    public function toggleNetwork(Request $request, DemandNetwork $demandNetwork, SiteConfigPublisher $publisher, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate(['is_enabled' => ['required', 'boolean']]);
        $before = (bool) $demandNetwork->is_enabled;
        $demandNetwork->update(['is_enabled' => (bool) $data['is_enabled']]);
        $audit->record('demand.network.enabled_changed', $request->user()->organization_id, $request->user(), $demandNetwork,
            ['is_enabled' => $before], ['is_enabled' => (bool) $data['is_enabled']]);
""", 1)
text = text.replace(
"""        abort_unless($demandSite->site_id === $site->id && $placement->site_id === $site->id, 404);
        $service->assignPlacement($demandSite, $placement, $this->placementData($request), $request->user());
""",
"""        abort_unless($demandSite->site_id === $site->id && $placement->site_id === $site->id, 404);
        $data = $this->placementData($request);
        $this->assertDirectPlacementSupported($demandSite, $placement, $data['integration_mode'] ?? null);
        $service->assignPlacement($demandSite, $placement, $data, $request->user());
""", 1)
text = text.replace(
"""        abort_unless($demandPlacement->demandSite->site_id === $site->id, 404);
        $data = $this->placementData($request);
        $demandPlacement->update($data + ['updated_by' => $request->user()->id]);
""",
"""        abort_unless($demandPlacement->demandSite->site_id === $site->id, 404);
        $data = $this->placementData($request);
        $this->assertDirectPlacementSupported($demandPlacement->demandSite, $demandPlacement->placement, $data['integration_mode'] ?? null);
        $demandPlacement->update($data + ['updated_by' => $request->user()->id]);
""", 1)
text = text.replace(
"""        $mode = $data['integration_mode'] ?? $demandPlacement->integration_mode ?? $demandPlacement->demandSite->integration_mode ?? $demandPlacement->demandSite->account->integration_mode;

        if ($tag !== '' && $mode === DemandIntegrationMode::DirectJs) {
""",
"""        $modeValue = $data['integration_mode'] ?? $demandPlacement->integration_mode ?? $demandPlacement->demandSite->integration_mode ?? $demandPlacement->demandSite->account->integration_mode;
        $mode = $modeValue instanceof DemandIntegrationMode ? $modeValue : DemandIntegrationMode::from((string) $modeValue);

        if ($tag !== '' && $mode === DemandIntegrationMode::DirectJs) {
""", 1)

marker = '    private function accountConfiguration(array $data, array $configuration): array\n'
helper = '''    private function assertDirectPlacementSupported(DemandSite $demandSite, Placement $placement, mixed $modeOverride): void
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

'''
if marker not in text: raise SystemExit('controller helper insertion marker missing')
text = text.replace(marker, helper + marker, 1)
p.write_text(text)

# Index UI: terminology, master, section navigation, network settings, account shortcuts, safe tag preview.
p = Path('resources/views/admin/demand/index.blade.php')
text = p.read_text()
text = text.replace("@section('title', 'Native demand networks')", "@section('title', 'Direct Demand')")
text = text.replace("@section('heading', 'Native & alternative demand')", "@section('heading', 'Direct Demand')")
text = text.replace('<p class="eyebrow">Optional demand layer</p>\n        <h2>HORUS_GAM remains primary</h2>\n        <p>Enable direct JavaScript or GAM-managed native demand without changing publisher installation code. Credentials remain private and never enter static configuration.</p>', '<p class="eyebrow">Independent direct monetization engine</p>\n        <h2>Networks, tags, health and reporting</h2>\n        <p>Operate Direct JS demand independently from GAM and Prebid without changing publisher installation code. Credentials remain server-side and public tags must pass structured review or isolated Custom Third Party validation.</p>')
text = text.replace("        <span class=\"pill\">{{ $accounts->total() }} accounts</span>\n    </div>\n</section>", "        <span class=\"pill\">{{ $accounts->total() }} accounts</span>\n        <span class=\"pill\">MASTER {{ $directDemandMasterEnabled ? 'ON' : 'OFF' }}</span>\n    </div>\n    <form class=\"form-stack\" method=\"POST\" action=\"{{ route('admin.demand.master') }}\">@csrf @method('PATCH')\n        <input type=\"hidden\" name=\"enabled\" value=\"{{ $directDemandMasterEnabled ? 0 : 1 }}\">\n        <label>Change reason<input class=\"hm-input\" name=\"reason\" required minlength=\"5\" placeholder=\"Operational reason for master change\"></label>\n        <button class=\"hm-button-primary\">{{ $directDemandMasterEnabled ? 'Pause Direct Demand master' : 'Enable Direct Demand master' }}</button>\n    </form>\n</section>\n\n<div class=\"status-row\" style=\"margin:1rem 0\">\n    <a class=\"pill\" href=\"#networks\">Networks</a><a class=\"pill\" href=\"#accounts\">Accounts</a><a class=\"pill\" href=\"#reports\">Reports</a><a class=\"pill\" href=\"{{ route('admin.sites.index') }}\">Websites / Placements</a><a class=\"pill\" href=\"{{ route('admin.supply-chain.ads-txt.index') }}\">Ads.txt</a>\n</div>")
text = text.replace('<article>\n    <div class="section-heading"><div><p class="eyebrow">Connector registry</p><h2>Available networks</h2></div></div>', '<article id="networks">\n    <div class="section-heading"><div><p class="eyebrow">Connector registry</p><h2>Networks and runtime health</h2></div></div>')
text = text.replace("            <p class=\"muted\">{{ implode(' · ', $network->capabilities ?? []) }}</p>", "            <p class=\"muted\">Direct JS: {{ $network->supports_direct_js ? 'supported' : 'off' }} · formats: {{ implode(', ', data_get($network->capabilities, 'supported_formats', [])) ?: 'provider-defined' }} · health: {{ data_get($network->metadata, 'operational_health', 'UNKNOWN') }}</p>")
network_form = '''            <form class="form-stack" method="POST" action="{{ route('admin.demand.networks.settings', $network) }}">@csrf @method('PUT')
                <label><input type="hidden" name="supports_direct_js" value="0"><input type="checkbox" name="supports_direct_js" value="1" @checked($network->supports_direct_js)> Supports Direct JS</label>
                <label>Formats<select class="hm-input" name="supported_formats[]" multiple>@foreach(['DISPLAY','NATIVE','VIDEO','OUTSTREAM'] as $format)<option value="{{ $format }}" @selected(in_array($format, data_get($network->capabilities, 'supported_formats', []), true))>{{ $format }}</option>@endforeach</select></label>
                <label>Integration modes<select class="hm-input" name="integration_modes[]" multiple>@foreach($modes as $mode)<option value="{{ $mode->value }}" @selected(in_array($mode->value, data_get($network->capabilities, 'integration_modes', []), true))>{{ $mode->value }}</option>@endforeach</select></label>
                <label>Approved script origins<textarea class="hm-input" rows="3" name="script_origins[]">{{ implode("\n", $network->script_origins ?? []) }}</textarea></label>
                <label>Health<select class="hm-input" name="operational_health">@foreach(['HEALTHY','DEGRADED','FAILED','UNKNOWN'] as $health)<option @selected(data_get($network->metadata, 'operational_health', 'UNKNOWN') === $health)>{{ $health }}</option>@endforeach</select></label>
                <button class="hm-button-secondary">Save network policy</button>
            </form>
            <form class="form-stack" method="POST" action="{{ route('admin.demand.networks.direct-js', $network) }}">@csrf @method('PATCH')
                <input type="hidden" name="enabled" value="1"><label>Runtime reason<input class="hm-input" name="reason" required minlength="5" value="Resume Direct Demand network runtime"></label><button class="hm-button-secondary">Ensure runtime ON</button>
            </form>
'''
needle = "            <form method=\"POST\" action=\"{{ route('admin.demand.networks.toggle', $network) }}\">@csrf @method('PATCH')"
idx = text.find(needle)
# Insert policy after each network toggle form closing by replacing shared exact block.
block = '''            <form method="POST" action="{{ route('admin.demand.networks.toggle', $network) }}">@csrf @method('PATCH')
                <input type="hidden" name="is_enabled" value="{{ $network->is_enabled ? 0 : 1 }}">
                <button class="hm-button-secondary">{{ $network->is_enabled ? 'Disable connector' : 'Enable connector' }}</button>
            </form>
'''
if block not in text: raise SystemExit('network toggle block missing')
text = text.replace(block, block + network_form, 1)
text = text.replace('<article>\n    <div class="section-heading"><div><p class="eyebrow">Accounts</p><h2>Network accounts and reporting</h2></div></div>', '<article id="accounts">\n    <div class="section-heading"><div><p class="eyebrow">Accounts</p><h2>Accounts, tags and health</h2></div></div>')
# account create convenience fields
text = text.replace('<label>Account / publisher identifier<input class="hm-input" name="account_identifier" placeholder="Provider-issued public account ID"></label>', '<label>Account / publisher identifier<input class="hm-input" name="account_identifier" placeholder="Provider-issued public account ID"></label>\n        <label>Reporting method<select class="hm-input" name="reporting_method"><option>API</option><option>CSV</option></select></label>\n        <label>Default render timeout ms<input class="hm-input" type="number" min="500" max="10000" name="default_render_timeout_ms" value="2500"></label>\n        <label>Approved script origin<input class="hm-input" type="url" name="approved_script_origins[]" placeholder="https://provider.example"></label>', 1)
# quick account toggle + preview form inserted before main account update form.
needle = '        <form class="form-grid" method="POST" action="{{ route(\'admin.demand.accounts.update\', $account) }}">'
insert = '''        <div class="status-row">
            <form method="POST" action="{{ route('admin.demand.accounts.enabled', $account) }}">@csrf @method('PATCH')<input type="hidden" name="enabled" value="{{ $account->is_enabled ? 0 : 1 }}"><button class="hm-button-secondary">{{ $account->is_enabled ? 'Disable account' : 'Enable account' }}</button></form>
        </div>
        <form class="form-stack" method="POST" action="{{ route('admin.demand.tags.preview', $account) }}" target="_blank">@csrf
            <label>Paste provider-issued public tag for safe preview<textarea class="hm-input" rows="4" name="tag" required placeholder="Paste public provider tag. Nothing is executed in Admin."></textarea></label>
            <button class="hm-button-secondary">Parse and review tag</button>
        </form>

'''
if needle not in text: raise SystemExit('account update form marker missing')
text = text.replace(needle, insert + needle, 1)
# update convenience fields after Account ID (applies each account via Blade loop)
needle = '<label>Account ID<input class="hm-input" name="account_identifier" value="{{ $account->account_identifier }}"></label>'
text = text.replace(needle, needle + '\n            <label>Reporting method<select class="hm-input" name="reporting_method"><option @selected(data_get($account->configuration, \'reporting_method\', \'API\') === \'API\')>API</option><option @selected(data_get($account->configuration, \'reporting_method\') === \'CSV\')>CSV</option></select></label>\n            <label>Default render timeout ms<input class="hm-input" type="number" min="500" max="10000" name="default_render_timeout_ms" value="{{ data_get($account->configuration, \'render_timeout_ms\', 2500) }}"></label>\n            <label>Approved script origin<input class="hm-input" type="url" name="approved_script_origins[]" value="{{ data_get($account->configuration, \'allowed_script_origins.0\') }}"></label>', 1)
text = text.replace('<section class="detail-grid">\n            <form class="form-stack" method="POST" action="{{ route(\'admin.demand.reports.api\', $account) }}">', '<section class="detail-grid" id="reports">\n            <form class="form-stack" method="POST" action="{{ route(\'admin.demand.reports.api\', $account) }}">', 1)
p.write_text(text)

# Site UI terminology + quick local toggles + tag review checkbox/paste field.
p = Path('resources/views/admin/demand/site.blade.php')
text = p.read_text()
text = text.replace("@section('title', $site->display_name.' Native demand')", "@section('title', $site->display_name.' Direct Demand')")
text = text.replace("@section('heading', 'Native demand · '.$site->display_name)", "@section('heading', 'Direct Demand · '.$site->display_name)")
text = text.replace('Adding, disabling, or reordering a native connector', 'Adding, disabling, or reordering a Direct Demand connector')
text = text.replace("'NATIVE ENABLED' : 'NATIVE DISABLED'", "'DIRECT DEMAND ENABLED' : 'DIRECT DEMAND DISABLED'")
text = text.replace("'Disable website native demand' : 'Enable website native demand'", "'Disable website Direct Demand' : 'Enable website Direct Demand'")
text = text.replace('Add native demand to this website', 'Assign Direct Demand account to this website')
text = text.replace('No native demand account is assigned to this website.', 'No Direct Demand account is assigned to this website.')
text = text.replace('Deploy native GAM objects', 'Deploy GAM-managed Direct Demand objects')
# site mapping quick toggle after status row
needle = '''        <div class="status-row">
            <span class="pill">{{ $mapping->approval_status->value }}</span>
            <span class="pill">{{ $mapping->is_enabled ? 'enabled' : 'disabled' }}</span>
            <span class="pill">{{ $mapping->sync_status->value }}</span>
        </div>
'''
insert = needle + '''        <form method="POST" action="{{ route('admin.sites.demand.mappings.enabled', [$site, $mapping]) }}">@csrf @method('PATCH')<input type="hidden" name="enabled" value="{{ $mapping->is_enabled ? 0 : 1 }}"><button class="hm-button-secondary">{{ $mapping->is_enabled ? 'Disable website mapping' : 'Enable website mapping' }}</button></form>
'''
if needle not in text: raise SystemExit('site mapping status marker missing')
text = text.replace(needle, insert, 1)
# placement local toggle alongside remote provider control
needle = '''                <form method="POST" action="{{ route('admin.sites.demand.placements.sync', [$site, $demandPlacement]) }}">@csrf<input type="hidden" name="dry_run" value="0"><button class="hm-button-secondary">Synchronize provider placement</button></form>
'''
insert = '''                <form method="POST" action="{{ route('admin.sites.demand.placements.enabled', [$site, $demandPlacement]) }}">@csrf @method('PATCH')<input type="hidden" name="enabled" value="{{ $demandPlacement->is_enabled ? 0 : 1 }}"><button class="hm-button-secondary">{{ $demandPlacement->is_enabled ? 'Disable local mapping' : 'Enable local mapping' }}</button></form>
'''
if needle not in text: raise SystemExit('placement sync marker missing')
text = text.replace(needle, insert + needle, 1)
# widget direct tag review fields.
needle = '''                <label>Approval<select class="hm-input" name="approval_status">@foreach($statuses as $status)<option value="{{ $status->value }}" @selected($status->value === 'APPROVED')>{{ $status->value }}</option>@endforeach</select></label>
                <label class="full">Widget configuration JSON<textarea class="hm-input" rows="3" name="configuration_json" placeholder='{"script_url":"https://allowlisted.example/widget.js","container_id":"widget-123"}'>{}</textarea></label>
'''
replacement = '''                <label>Approval<select class="hm-input" name="approval_status">@foreach($statuses as $status)<option value="{{ $status->value }}" @selected($status->value === 'NOT_SUBMITTED')>{{ $status->value }}</option>@endforeach</select></label>
                <label class="full">Provider-issued public tag<textarea class="hm-input" rows="6" name="direct_tag_template" placeholder="Paste the provider-issued public tag. Structured providers are parsed; Custom Third Party tags require isolated CSP origins."></textarea></label>
                <label class="full">Widget configuration JSON<textarea class="hm-input" rows="3" name="configuration_json" placeholder='{"isolation_allowed_origins":["https://provider.example"]}'>{}</textarea></label>
                <label class="full"><input type="hidden" name="tag_review_approved" value="0"><input type="checkbox" name="tag_review_approved" value="1"> I reviewed the detected public scripts/container/warnings and approve this tag for production when Approval is set to APPROVED.</label>
'''
if needle not in text: raise SystemExit('widget approval marker missing')
text = text.replace(needle, replacement, 1)
p.write_text(text)

# Publisher white-label site copy if previous exact replacement did not catch it.
p = Path('resources/views/publisher/sites/show.blade.php')
text = p.read_text().replace('Native demand', 'Direct Monetization').replace('Manage native demand', 'Direct Monetization status')
p.write_text(text)

Path('scripts/task18_harden.py').unlink(missing_ok=True)
Path('.github/workflows/task18-harden.yml').unlink(missing_ok=True)
