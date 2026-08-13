@extends('layouts.admin')
@section('title', 'Direct Demand')
@section('heading', 'Direct Demand')
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Independent direct monetization engine</p>
        <h2>Networks, tags, health and reporting</h2>
        <p>Operate Direct JS demand independently from GAM and Prebid without changing publisher installation code. Credentials remain server-side and public tags must pass structured review or isolated Custom Third Party validation.</p>
    </div>
    <div class="status-row">
        <span class="pill">{{ $networks->where('is_enabled', true)->count() }} connectors enabled</span>
        <span class="pill">{{ $accounts->total() }} accounts</span>
        <span class="pill">MASTER {{ $directDemandMasterEnabled ? 'ON' : 'OFF' }}</span>
    </div>
    <form class="form-stack" method="POST" action="{{ route('admin.demand.master') }}">@csrf @method('PATCH')
        <input type="hidden" name="enabled" value="{{ $directDemandMasterEnabled ? 0 : 1 }}">
        <label>Change reason<input class="hm-input" name="reason" required minlength="5" placeholder="Operational reason for master change"></label>
        <button class="hm-button-primary">{{ $directDemandMasterEnabled ? 'Pause Direct Demand master' : 'Enable Direct Demand master' }}</button>
    </form>
</section>

<div class="status-row" style="margin:1rem 0">
    <a class="pill" href="#networks">Networks</a><a class="pill" href="#accounts">Accounts</a><a class="pill" href="#reports">Reports</a><a class="pill" href="{{ route('admin.sites.index') }}">Websites / Placements</a><a class="pill" href="{{ route('admin.compliance.ads-txt.index') }}">Ads.txt</a>
</div>

<article id="networks">
    <div class="section-heading"><div><p class="eyebrow">Connector registry</p><h2>Networks and runtime health</h2></div></div>
    <div class="detail-grid">
        @foreach($networks as $network)
        <div class="domain-card">
            <div>
                <strong>{{ $network->name }}</strong>
                <span class="pill">{{ $network->code->value }}</span>
                <span class="pill">{{ $network->is_enabled ? 'ENABLED' : 'DISABLED' }}</span>
            </div>
            <p class="muted">Direct JS: {{ $network->supports_direct_js ? 'supported' : 'off' }} · formats: {{ implode(', ', data_get($network->capabilities, 'supported_formats', [])) ?: 'provider-defined' }} · health: {{ data_get($network->metadata, 'operational_health', 'UNKNOWN') }}</p>
            <form method="POST" action="{{ route('admin.demand.networks.toggle', $network) }}">@csrf @method('PATCH')
                <input type="hidden" name="is_enabled" value="{{ $network->is_enabled ? 0 : 1 }}">
                <button class="hm-button-secondary">{{ $network->is_enabled ? 'Disable connector' : 'Enable connector' }}</button>
            </form>
            <form class="form-stack" method="POST" action="{{ route('admin.demand.networks.settings', $network) }}">@csrf @method('PUT')
                <label><input type="hidden" name="supports_direct_js" value="0"><input type="checkbox" name="supports_direct_js" value="1" @checked($network->supports_direct_js)> Supports Direct JS</label>
                <label>Formats<select class="hm-input" name="supported_formats[]" multiple>@foreach(['DISPLAY','NATIVE','VIDEO','OUTSTREAM'] as $format)<option value="{{ $format }}" @selected(in_array($format, data_get($network->capabilities, 'supported_formats', []), true))>{{ $format }}</option>@endforeach</select></label>
                <label>Integration modes<select class="hm-input" name="integration_modes[]" multiple>@foreach($modes as $mode)<option value="{{ $mode->value }}" @selected(in_array($mode->value, data_get($network->capabilities, 'integration_modes', []), true))>{{ $mode->value }}</option>@endforeach</select></label>
                <label>Approved script origins<textarea class="hm-input" rows="3" name="script_origins[]">{{ implode("
", $network->script_origins ?? []) }}</textarea></label>
                <label>Health<select class="hm-input" name="operational_health">@foreach(['HEALTHY','DEGRADED','FAILED','UNKNOWN'] as $health)<option @selected(data_get($network->metadata, 'operational_health', 'UNKNOWN') === $health)>{{ $health }}</option>@endforeach</select></label>
                <button class="hm-button-secondary">Save network policy</button>
            </form>
            <form class="form-stack" method="POST" action="{{ route('admin.demand.networks.direct-js', $network) }}">@csrf @method('PATCH')
                <input type="hidden" name="enabled" value="1"><label>Runtime reason<input class="hm-input" name="reason" required minlength="5" value="Resume Direct Demand network runtime"></label><button class="hm-button-secondary">Ensure runtime ON</button>
            </form>
        </div>
        @endforeach
    </div>
</article>

<section class="detail-grid">
<article>
    <p class="eyebrow">Account management</p>
    <h3>Add demand account</h3>
    <form class="form-stack" method="POST" action="{{ route('admin.demand.accounts.store') }}">@csrf
        <label>Network
            <select class="hm-input" name="demand_network_id" required>
                @foreach($networks as $network)<option value="{{ $network->id }}">{{ $network->name }} · {{ $network->code->value }}</option>@endforeach
            </select>
        </label>
        <label>Name<input class="hm-input" name="name" required placeholder="Horus MGID MENA"></label>
        <label>Scope
            <select class="hm-input" name="scope">@foreach($scopes as $scope)<option value="{{ $scope->value }}">{{ $scope->value }}</option>@endforeach</select>
        </label>
        <label>Publisher
            <select class="hm-input" name="publisher_id"><option value="">Not publisher-scoped</option>@foreach($publishers as $publisher)<option value="{{ $publisher->id }}">{{ $publisher->display_name }}</option>@endforeach</select>
        </label>
        <label>MCM partner organization
            <select class="hm-input" name="partner_organization_id"><option value="">Not partner-scoped</option>@foreach($partners as $partner)<option value="{{ $partner->id }}">{{ $partner->name }}</option>@endforeach</select>
        </label>
        <label>Integration mode
            <select class="hm-input" name="integration_mode">@foreach($modes as $mode)<option value="{{ $mode->value }}">{{ $mode->value }}</option>@endforeach</select>
        </label>
        <label>Approval status
            <select class="hm-input" name="approval_status">@foreach($statuses as $status)<option value="{{ $status->value }}">{{ $status->value }}</option>@endforeach</select>
        </label>
        <label>Account / publisher identifier<input class="hm-input" name="account_identifier" placeholder="Provider-issued public account ID"></label>
        <label>Reporting method<select class="hm-input" name="reporting_method"><option>API</option><option>CSV</option></select></label>
        <label>Default render timeout ms<input class="hm-input" type="number" min="500" max="10000" name="default_render_timeout_ms" value="2500"></label>
        <label>Approved script origin<input class="hm-input" type="url" name="approved_script_origins[]" placeholder="https://provider.example"></label>
        <label>Revenue share %<input class="hm-input" type="number" step="0.001" min="0" max="100" name="revenue_share_percent" value="0" required></label>
        <label>Fallback priority<input class="hm-input" type="number" min="0" max="10000" name="fallback_priority" value="100" required></label>
        <label>Non-secret configuration JSON<textarea class="hm-input" rows="9" name="configuration_json" placeholder='{"script_url":"https://approved.example/widget.js","ads_txt_records":[]}'>{}</textarea></label>
        <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" checked> Enabled</label>
        <label><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1"> Default account for this network</label>
        <button class="hm-button-primary">Create demand account</button>
    </form>
</article>

<article>
    <p class="eyebrow">Credential security</p>
    <h3>Configuration rules</h3>
    <p>Store only public mapping data in configuration JSON. API keys and tokens must be referenced using <code>env:</code> or <code>file:</code>.</p>
    <p>Provider APIs that require approval remain disabled until the approved endpoint paths and credential references are supplied. No placeholder credentials are generated.</p>
    <p>Disabling a connector removes it from newly published website configurations but does not delete imported historical reports.</p>
</article>
</section>

<article id="accounts">
    <div class="section-heading"><div><p class="eyebrow">Accounts</p><h2>Accounts, tags and health</h2></div></div>
    @forelse($accounts as $account)
    @php($financial = data_get($financialStatuses, $account->id))
    @php($binding = data_get($financial, 'binding'))
    <div class="domain-card">
        <div class="section-heading">
            <div>
                <strong>{{ $account->name }}</strong>
                <span class="pill">{{ $account->network->code->value }}</span>
                <span class="pill">{{ $account->approval_status->value }}</span>
                <span class="pill">{{ $account->integration_mode->value }}</span>
                <span class="pill">{{ $account->is_enabled ? 'enabled' : 'disabled' }}</span>
                <span class="pill">FINANCE {{ data_get($financial, 'status', 'NOT_CONFIGURED') }}</span>
            </div>
            <div class="status-row">
                <span class="pill">{{ data_get($summaries, $account->id.'.impressions', 0) }} impressions</span>
                <span class="pill">{{ data_get($summaries, $account->id.'.clicks', 0) }} clicks</span>
            </div>
        </div>

        <section class="detail-grid">
            <div>
                <p class="eyebrow">Provider financial source of truth</p>
                <h4>{{ $binding?->source?->name ?? 'Not configured' }}</h4>
                <p class="muted">
                    Method {{ $binding?->reporting_method?->value ?? '—' }} · currency {{ $binding?->currency ?? '—' }} ·
                    finality {{ $binding?->is_finalized_capable ? 'CAPABLE' : 'NOT CAPABLE' }} ·
                    last successful {{ data_get($financial, 'last_successful_import_at') ?? 'never' }} ·
                    last finalized {{ data_get($financial, 'last_finalized_data_at') ?? 'never' }} ·
                    reconciliation {{ data_get($financial, 'reconciliation_status') ?? 'not run' }}
                </p>
                @foreach(data_get($financial, 'reasons', []) as $reason)<p class="error"><strong>{{ $reason['code'] }}</strong>: {{ $reason['message'] }}</p>@endforeach
            </div>
            <form class="form-stack" method="POST" action="{{ route('admin.demand.accounts.financial-source', $account) }}">@csrf @method('PUT')
                <label>Financial source<select class="hm-input" name="report_source_id" required>@foreach($reportSources as $source)<option value="{{ $source->id }}" @selected($binding?->report_source_id === $source->id)>{{ $source->name }} · {{ $source->code->value }}</option>@endforeach</select></label>
                <label>Reporting method<select class="hm-input" name="reporting_method" required>@foreach($financialMethods as $method)<option value="{{ $method->value }}" @selected($binding?->reporting_method === $method)>{{ $method->value }}</option>@endforeach</select></label>
                <label>Currency<input class="hm-input" name="currency" maxlength="3" value="{{ $binding?->currency ?? data_get($account->configuration, 'currency', 'USD') }}" required></label>
                <label>Timezone<input class="hm-input" name="timezone" value="{{ $binding?->timezone ?? 'UTC' }}" required></label>
                <label>Non-secret reporting metadata JSON<textarea class="hm-input" name="configuration_json" rows="3">{{ json_encode($binding?->configuration ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea></label>
                <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" @checked($binding?->is_enabled ?? true)> Financial binding enabled</label>
                <button class="hm-button-secondary">Save financial source</button>
            </form>
        </section>

        <div class="status-row">
            <form method="POST" action="{{ route('admin.demand.accounts.enabled', $account) }}">@csrf @method('PATCH')<input type="hidden" name="enabled" value="{{ $account->is_enabled ? 0 : 1 }}"><button class="hm-button-secondary">{{ $account->is_enabled ? 'Disable account' : 'Enable account' }}</button></form>
        </div>
        <form class="form-stack" method="POST" action="{{ route('admin.demand.tags.preview', $account) }}" target="_blank">@csrf
            <label>Paste provider-issued public tag for safe preview<textarea class="hm-input" rows="4" name="tag" required placeholder="Paste public provider tag. Nothing is executed in Admin."></textarea></label>
            <button class="hm-button-secondary">Parse and review tag</button>
        </form>

        <form class="form-grid" method="POST" action="{{ route('admin.demand.accounts.update', $account) }}">@csrf @method('PUT')
            <label>Name<input class="hm-input" name="name" value="{{ $account->name }}" required></label>
            <label>Scope<select class="hm-input" name="scope">@foreach($scopes as $scope)<option value="{{ $scope->value }}" @selected($account->scope === $scope)>{{ $scope->value }}</option>@endforeach</select></label>
            <label>Mode<select class="hm-input" name="integration_mode">@foreach($modes as $mode)<option value="{{ $mode->value }}" @selected($account->integration_mode === $mode)>{{ $mode->value }}</option>@endforeach</select></label>
            <label>Approval<select class="hm-input" name="approval_status">@foreach($statuses as $status)<option value="{{ $status->value }}" @selected($account->approval_status === $status)>{{ $status->value }}</option>@endforeach</select></label>
            <label>Account ID<input class="hm-input" name="account_identifier" value="{{ $account->account_identifier }}"></label>
            <label>Reporting method<select class="hm-input" name="reporting_method"><option @selected(data_get($account->configuration, 'reporting_method', 'API') === 'API')>API</option><option @selected(data_get($account->configuration, 'reporting_method') === 'CSV')>CSV</option></select></label>
            <label>Default render timeout ms<input class="hm-input" type="number" min="500" max="10000" name="default_render_timeout_ms" value="{{ data_get($account->configuration, 'render_timeout_ms', 2500) }}"></label>
            <label>Approved script origin<input class="hm-input" type="url" name="approved_script_origins[]" value="{{ data_get($account->configuration, 'allowed_script_origins.0') }}"></label>
            <label>Revenue share %<input class="hm-input" type="number" step="0.001" min="0" max="100" name="revenue_share_percent" value="{{ $account->revenue_share_percent }}"></label>
            <label>Fallback priority<input class="hm-input" type="number" name="fallback_priority" value="{{ $account->fallback_priority }}"></label>
            <label class="full">Non-secret configuration JSON<textarea class="hm-input" rows="7" name="configuration_json">{{ json_encode($account->configuration ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea></label>
            <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" @checked($account->is_enabled)> Enabled</label>
            <label><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1" @checked($account->is_default)> Default</label>
            <div><button class="hm-button-secondary">Save account</button></div>
        </form>

        <section class="detail-grid">
            <form class="form-stack" method="POST" action="{{ route('admin.demand.credentials.store', $account) }}">@csrf
                <h4>Encrypted credential reference</h4>
                <label>Credential key<input class="hm-input" name="credential_key" value="api_token" required></label>
                <label>Reference<input class="hm-input" name="reference" placeholder="env:MGID_API_TOKEN" required></label>
                <label>Hint<input class="hm-input" name="hint" placeholder="token ending ****1234"></label>
                <label>Capability<input class="hm-input" name="capability" value="API"></label>
                <button class="hm-button-secondary">Save credential reference</button>
            </form>

            <div>
                <h4>Connection and approval</h4>
                <form class="inline-form" method="POST" action="{{ route('admin.demand.accounts.test', $account) }}">@csrf
                    <input type="hidden" name="dry_run" value="0"><button class="hm-button-secondary">Test account</button>
                </form>
                <form class="form-stack" method="POST" action="{{ route('admin.demand.accounts.review', $account) }}">@csrf
                    <select class="hm-input" name="approval_status">@foreach($statuses as $status)<option value="{{ $status->value }}">{{ $status->value }}</option>@endforeach</select>
                    <textarea class="hm-input" name="reason" placeholder="Review reason"></textarea>
                    <button class="hm-button-secondary">Record review</button>
                </form>
            </div>
        </section>

        <section class="detail-grid" id="reports">
            <form class="form-stack" method="POST" action="{{ route('admin.demand.reports.api', $account) }}">@csrf
                <h4>API report</h4>
                <label>From<input class="hm-input" type="date" name="from" required></label>
                <label>To<input class="hm-input" type="date" name="to" required></label>
                <button class="hm-button-secondary">Run approved report API</button>
            </form>
            <form class="form-stack" method="POST" enctype="multipart/form-data" action="{{ route('admin.demand.reports.csv', $account) }}">@csrf
                <h4>CSV fallback</h4>
                <label>From<input class="hm-input" type="date" name="from" required></label>
                <label>To<input class="hm-input" type="date" name="to" required></label>
                <label>CSV report<input class="hm-input" type="file" name="report" accept=".csv,text/csv" required></label>
                <button class="hm-button-secondary">Import aggregated report</button>
            </form>
        </section>
    </div>
    @empty<p class="muted">No demand accounts have been configured.</p>@endforelse
    {{ $accounts->links() }}
</article>
@endsection
