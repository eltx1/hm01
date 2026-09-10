@extends('layouts.admin')
@section('title', $account->name.' · Direct Demand')
@section('heading', 'Direct Demand Account')
@section('content')
@php
    $binding = data_get($financial, 'binding');
    $publisher = $account->publisher;
    $partner = $account->partnerOrganization;
    $origins = implode("\n", (array) data_get($account->configuration, 'allowed_script_origins', []));
@endphp

<section class="hero workspace-section">
    <div>
        <p class="eyebrow">{{ $account->network->name }} · {{ $account->network->code->value }}</p>
        <h2>{{ $account->name }}</h2>
        <p>One account workspace for identity, delivery settings, public tag review, finance, credentials and reporting.</p>
        <div class="status-row">
            <span class="pill">{{ $account->scope->value }}</span>
            <span class="pill">{{ $account->integration_mode->value }}</span>
            <span class="pill">{{ $account->approval_status->value }}</span>
            <span class="pill">{{ $account->is_enabled ? 'ENABLED' : 'DISABLED' }}</span>
            <span class="pill">FINANCE {{ data_get($financial, 'status', 'NOT_CONFIGURED') }}</span>
        </div>
        <div class="status-row" style="margin-top:1rem">
            <a class="hm-button-secondary button-link" href="{{ route('admin.demand.index') }}">← All demand accounts</a>
            <form method="POST" action="{{ route('admin.demand.accounts.enabled', $account) }}">@csrf @method('PATCH')
                <input type="hidden" name="enabled" value="{{ $account->is_enabled ? 0 : 1 }}">
                <button class="hm-button-secondary">{{ $account->is_enabled ? 'Disable delivery' : 'Enable delivery' }}</button>
            </form>
        </div>
    </div>
</section>

<section class="metric-grid" aria-label="Demand account summary">
    <article><p class="eyebrow">Impressions</p><strong class="metric">{{ number_format((int) data_get($summary, 'impressions', 0)) }}</strong><span class="muted">Imported aggregate</span></article>
    <article><p class="eyebrow">Clicks</p><strong class="metric">{{ number_format((int) data_get($summary, 'clicks', 0)) }}</strong><span class="muted">Imported aggregate</span></article>
    <article><p class="eyebrow">Websites</p><strong class="metric">{{ $account->sites->count() }}</strong><span class="muted">Mapped in Horus</span></article>
    <article><p class="eyebrow">Priority</p><strong class="metric">{{ $account->fallback_priority }}</strong><span class="muted">Fallback order</span></article>
</section>

<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Ownership</p><h2>Account identity</h2></div></div>
    <div class="detail-grid">
        <div class="domain-card">
            <p class="eyebrow">Horus scope</p>
            <h3>{{ str($account->scope->value)->replace('_', ' ')->headline() }}</h3>
            @if($account->scope->value === 'PUBLISHER')
                @if($publisher)
                    <p><strong>{{ $publisher->display_name }}</strong></p>
                    <p class="muted">Horus Publisher ID: <code>{{ $publisher->id }}</code></p>
                    <a class="section-anchor" href="{{ route('admin.publishers.show', $publisher) }}">Open Publisher 360 →</a>
                @else
                    <p class="error">Publisher-scoped account has no resolvable Publisher record. Review data integrity before delivery.</p>
                @endif
            @elseif($account->scope->value === 'MCM_PARTNER')
                <p><strong>{{ $partner?->name ?? 'Partner not resolved' }}</strong></p>
                @if($partner)<p class="muted">Horus organization ID: <code>{{ $partner->id }}</code></p>@endif
            @else
                <p class="muted">Owned by Horus Media rather than an individual publisher or MCM partner.</p>
            @endif
        </div>
        <div class="domain-card">
            <p class="eyebrow">External provider</p>
            <h3>Provider account identifier</h3>
            <p><strong>{{ $account->account_identifier ?: 'Not supplied' }}</strong></p>
            <p class="muted">Optional ID issued by the demand provider. This is not the Horus Publisher ID. A manual Google tag can leave this blank unless a provider identifier is specifically needed.</p>
        </div>
    </div>
</article>

<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Delivery configuration</p><h2>Account settings</h2></div></div>
    <p class="muted">Scope ownership is intentionally read-only after creation to protect reporting and mappings.</p>
    <form class="form-grid" method="POST" action="{{ route('admin.demand.accounts.update', $account) }}">
        @csrf @method('PUT')
        <label>Name<input class="hm-input" name="name" value="{{ old('name', $account->name) }}" required></label>
        <label>Integration mode<select class="hm-input" name="integration_mode" required>@foreach($modes as $mode)<option value="{{ $mode->value }}" @selected(old('integration_mode', $account->integration_mode->value) === $mode->value)>{{ $mode->value }}</option>@endforeach</select></label>
        <label>Approval<select class="hm-input" name="approval_status" required>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(old('approval_status', $account->approval_status->value) === $status->value)>{{ $status->value }}</option>@endforeach</select></label>
        <label>Provider account identifier <span class="muted">(optional)</span><input class="hm-input" name="account_identifier" value="{{ old('account_identifier', $account->account_identifier) }}" placeholder="Provider-issued public account ID"><span class="muted">Not the Horus Publisher ID.</span></label>
        <label>Reporting method<select class="hm-input" name="reporting_method"><option value="">Not configured</option><option value="API" @selected(old('reporting_method', data_get($account->configuration, 'reporting_method')) === 'API')>API</option><option value="CSV" @selected(old('reporting_method', data_get($account->configuration, 'reporting_method')) === 'CSV')>CSV</option></select></label>
        <label>Default render timeout ms<input class="hm-input" type="number" min="500" max="10000" name="default_render_timeout_ms" value="{{ old('default_render_timeout_ms', data_get($account->configuration, 'render_timeout_ms', 2500)) }}"></label>
        <label>Revenue share %<input class="hm-input" type="number" step="0.001" min="0" max="100" name="revenue_share_percent" value="{{ old('revenue_share_percent', $account->revenue_share_percent) }}" required></label>
        <label>Fallback priority<input class="hm-input" type="number" min="0" max="10000" name="fallback_priority" value="{{ old('fallback_priority', $account->fallback_priority) }}" required></label>
        <label class="full">Approved script origins <span class="muted">(optional)</span><textarea class="hm-input" rows="3" name="approved_script_origins_text" placeholder="https://securepubads.g.doubleclick.net">{{ old('approved_script_origins_text', $origins) }}</textarea><span class="muted">One HTTPS origin per line. Leaving this blank is valid; blank rows are ignored.</span></label>
        <label class="full">Non-secret configuration JSON<textarea class="hm-input" rows="7" name="configuration_json">{{ old('configuration_json', json_encode($account->configuration ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) }}</textarea><span class="muted">Public configuration only. Never paste API keys, OAuth secrets or private tokens.</span></label>
        <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" @checked((bool) old('is_enabled', $account->is_enabled))> Enabled</label>
        <label><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1" @checked((bool) old('is_default', $account->is_default))> Default for this network</label>
        <div class="full"><button class="hm-button-primary">Save account settings</button></div>
    </form>
</article>

<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Publisher inventory</p><h2>Website mappings</h2></div></div>
    <div class="compact-list">
        @forelse($account->sites as $mapping)
            @if($mapping->site)
                <a class="compact-row" href="{{ route('admin.sites.demand.status', $mapping->site) }}">
                    <div><strong>{{ $mapping->site->display_name }}</strong><p>{{ $mapping->site->primary_domain }} · {{ $mapping->approval_status->value }} · {{ $mapping->is_enabled ? 'enabled' : 'disabled' }}</p></div>
                    <span class="pill">Open mapping →</span>
                </a>
            @endif
        @empty
            <p class="muted">This demand account is not mapped to a website yet. Open a website and use its Direct Demand workspace to assign it.</p>
        @endforelse
    </div>
</article>

<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Safe public-tag inspection</p><h2>Parse and review tag</h2></div></div>
    <p class="muted">Preview parses provider-issued markup without executing it in Admin. Use this before saving a production widget/tag on a placement.</p>
    <form class="form-stack" method="POST" action="{{ route('admin.demand.tags.preview', $account) }}" target="_blank">
        @csrf
        <label>Provider-issued public tag<textarea class="hm-input" rows="8" name="tag" required placeholder="Paste the public provider tag. Nothing is executed in Admin."></textarea></label>
        <button class="hm-button-secondary">Parse and review tag</button>
    </form>
</article>

<section class="detail-grid">
    <article class="workspace-section">
        <p class="eyebrow">Provider financial source of truth</p>
        <h2>{{ $binding?->source?->name ?? 'Not configured' }}</h2>
        <p class="muted">Status {{ data_get($financial, 'status', 'NOT_CONFIGURED') }} · last successful {{ data_get($financial, 'last_successful_import_at') ?? 'never' }} · last finalized {{ data_get($financial, 'last_finalized_data_at') ?? 'never' }}</p>
        @foreach(data_get($financial, 'reasons', []) as $reason)<p class="error"><strong>{{ $reason['code'] }}</strong>: {{ $reason['message'] }}</p>@endforeach
        <form class="form-stack" method="POST" action="{{ route('admin.demand.accounts.financial-source', $account) }}">
            @csrf @method('PUT')
            <label>Financial source<select class="hm-input" name="report_source_id" required>@foreach($reportSources as $source)<option value="{{ $source->id }}" @selected($binding?->report_source_id === $source->id)>{{ $source->name }} · {{ $source->code->value }}</option>@endforeach</select></label>
            <label>Reporting method<select class="hm-input" name="reporting_method" required>@foreach($financialMethods as $method)<option value="{{ $method->value }}" @selected($binding?->reporting_method === $method)>{{ $method->value }}</option>@endforeach</select></label>
            <label>Currency<input class="hm-input" name="currency" maxlength="3" value="{{ $binding?->currency ?? data_get($account->configuration, 'currency', 'USD') }}" required></label>
            <label>Timezone<input class="hm-input" name="timezone" value="{{ $binding?->timezone ?? 'UTC' }}" required></label>
            <label>Non-secret reporting metadata JSON<textarea class="hm-input" name="configuration_json" rows="4">{{ json_encode($binding?->configuration ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea></label>
            <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" @checked($binding?->is_enabled ?? true)> Financial binding enabled</label>
            <button class="hm-button-secondary">Save financial source</button>
        </form>
    </article>

    <article class="workspace-section">
        <p class="eyebrow">Secret separation</p>
        <h2>Credential references</h2>
        <p class="muted">Credentials stay server-side. The UI stores references such as <code>env:</code> or <code>file:</code>, never raw secrets.</p>
        <div class="compact-list">
            @forelse($account->credentials as $credential)
                <div class="compact-row"><div><strong>{{ $credential->credential_key }}</strong><p>{{ $credential->capability }} · {{ $credential->hint ?: 'No public hint' }}</p></div><span class="pill">STORED</span></div>
            @empty<p class="muted">No credential references configured. Manual public-tag delivery normally does not need one.</p>@endforelse
        </div>
        <form class="form-stack" method="POST" action="{{ route('admin.demand.credentials.store', $account) }}">
            @csrf
            <label>Credential key<input class="hm-input" name="credential_key" value="api_token" required></label>
            <label>Reference<input class="hm-input" name="reference" placeholder="env:PROVIDER_API_TOKEN" required></label>
            <label>Hint<input class="hm-input" name="hint" placeholder="token ending ****1234"></label>
            <label>Capability<input class="hm-input" name="capability" value="API"></label>
            <button class="hm-button-secondary">Save credential reference</button>
        </form>
    </article>
</section>

<section class="detail-grid">
    <article class="workspace-section">
        <p class="eyebrow">Connection and governance</p><h2>Test / review</h2>
        <form class="form-stack" method="POST" action="{{ route('admin.demand.accounts.test', $account) }}">@csrf<input type="hidden" name="dry_run" value="0"><button class="hm-button-secondary">Test account</button></form>
        <form class="form-stack" method="POST" action="{{ route('admin.demand.accounts.review', $account) }}">@csrf
            <label>Approval status<select class="hm-input" name="approval_status">@foreach($statuses as $status)<option value="{{ $status->value }}" @selected($account->approval_status === $status)>{{ $status->value }}</option>@endforeach</select></label>
            <label>Review reason<textarea class="hm-input" name="reason" rows="3" placeholder="Review reason"></textarea></label>
            <button class="hm-button-secondary">Record review</button>
        </form>
    </article>

    <article class="workspace-section">
        <p class="eyebrow">Aggregated reporting</p><h2>Import reports</h2>
        <form class="form-stack" method="POST" action="{{ route('admin.demand.reports.api', $account) }}">@csrf
            <h3>API report</h3><label>From<input class="hm-input" type="date" name="from" required></label><label>To<input class="hm-input" type="date" name="to" required></label><button class="hm-button-secondary">Run approved report API</button>
        </form>
        <form class="form-stack" method="POST" enctype="multipart/form-data" action="{{ route('admin.demand.reports.csv', $account) }}">@csrf
            <h3>CSV fallback</h3><label>From<input class="hm-input" type="date" name="from" required></label><label>To<input class="hm-input" type="date" name="to" required></label><label>CSV report<input class="hm-input" type="file" name="report" accept=".csv,text/csv" required></label><button class="hm-button-secondary">Import aggregated report</button>
        </form>
    </article>
</section>
@endsection
