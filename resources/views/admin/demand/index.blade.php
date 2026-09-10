@extends('layouts.admin')
@section('title', 'Direct Demand')
@section('heading', 'Direct Demand')
@section('content')
<section class="hero workspace-section">
    <div>
        <p class="eyebrow">Independent direct monetization engine</p>
        <h2>Demand accounts</h2>
        <p>Manage Direct JS and manual-tag demand independently from GAM and Prebid. Open each account in its own workspace for identity, tags, finance, credentials and reporting.</p>
        <div class="status-row">
            <span class="pill">{{ $networks->where('is_enabled', true)->count() }} CONNECTORS</span>
            <span class="pill">{{ $accounts->total() }} ACCOUNTS</span>
            <span class="pill">MASTER {{ $directDemandMasterEnabled ? 'ON' : 'OFF' }}</span>
        </div>
        <div class="status-row" style="margin-top:1rem">
            <a class="hm-button-primary button-link" href="{{ route('admin.demand.accounts.create') }}">+ Add demand account</a>
            <a class="hm-button-secondary button-link" href="{{ route('admin.sites.index') }}">Websites / Placements</a>
            <a class="hm-button-secondary button-link" href="{{ route('admin.compliance.ads-txt.index') }}">Ads.txt</a>
        </div>
    </div>
    <form class="form-stack" method="POST" action="{{ route('admin.demand.master') }}">
        @csrf @method('PATCH')
        <input type="hidden" name="enabled" value="{{ $directDemandMasterEnabled ? 0 : 1 }}">
        <label>Change reason<input class="hm-input" name="reason" required minlength="5" placeholder="Operational reason for master change"></label>
        <button class="hm-button-secondary">{{ $directDemandMasterEnabled ? 'Pause Direct Demand master' : 'Enable Direct Demand master' }}</button>
    </form>
</section>

<article id="accounts" class="workspace-section">
    <div class="workspace-heading">
        <div><p class="eyebrow">Account registry</p><h2>Configured demand accounts</h2></div>
        <a class="hm-button-primary button-link" href="{{ route('admin.demand.accounts.create') }}">Add account</a>
    </div>
    <p class="muted">Each row is one provider relationship. Horus ownership scope and external provider identifiers are kept separate.</p>

    <div class="compact-list">
        @forelse($accounts as $account)
            @php($financial = data_get($financialStatuses, $account->id))
            <a class="compact-row" href="{{ route('admin.demand.accounts.show', $account) }}">
                <div>
                    <div class="status-row">
                        <strong>{{ $account->name }}</strong>
                        <span class="pill">{{ $account->network->name }}</span>
                        <span class="pill">{{ $account->approval_status->value }}</span>
                        <span class="pill">{{ $account->is_enabled ? 'ENABLED' : 'DISABLED' }}</span>
                    </div>
                    <p>
                        {{ $account->scope->value }}
                        @if($account->scope->value === 'PUBLISHER')
                            · {{ $account->publisher?->display_name ?? 'Publisher not resolved' }}
                        @elseif($account->scope->value === 'MCM_PARTNER')
                            · partner-scoped
                        @endif
                        · {{ $account->integration_mode->value }}
                    </p>
                    <p class="muted">
                        {{ number_format((int) data_get($summaries, $account->id.'.impressions', 0)) }} impressions
                        · {{ number_format((int) data_get($summaries, $account->id.'.clicks', 0)) }} clicks
                        · {{ $account->sites->count() }} website mapping(s)
                        · Finance {{ data_get($financial, 'status', 'NOT_CONFIGURED') }}
                    </p>
                </div>
                <span class="pill">Open account →</span>
            </a>
        @empty
            <div class="domain-card">
                <h3>No demand accounts yet</h3>
                <p class="muted">Create the provider account first, then map it to a website and placement.</p>
                <a class="hm-button-primary button-link" href="{{ route('admin.demand.accounts.create') }}">Create first account</a>
            </div>
        @endforelse
    </div>
    {{ $accounts->links() }}
</article>

<article id="networks" class="workspace-section">
    <div class="workspace-heading">
        <div><p class="eyebrow">Connector registry</p><h2>Networks and runtime health</h2></div>
    </div>
    <p class="muted">Network-level controls are secondary infrastructure settings. Account editing now lives inside each account workspace.</p>

    <div class="compact-list">
        @foreach($networks as $network)
            <details class="domain-card">
                <summary>
                    <strong>{{ $network->name }}</strong>
                    <span class="pill">{{ $network->code->value }}</span>
                    <span class="pill">{{ $network->is_enabled ? 'ENABLED' : 'DISABLED' }}</span>
                    <span class="pill">{{ $network->accounts_count }} ACCOUNT(S)</span>
                </summary>
                <p class="muted">Direct JS: {{ $network->supports_direct_js ? 'supported' : 'off' }} · formats: {{ implode(', ', data_get($network->capabilities, 'supported_formats', [])) ?: 'provider-defined' }} · health: {{ data_get($network->metadata, 'operational_health', 'UNKNOWN') }}</p>

                <div class="status-row">
                    <form method="POST" action="{{ route('admin.demand.networks.toggle', $network) }}">
                        @csrf @method('PATCH')
                        <input type="hidden" name="is_enabled" value="{{ $network->is_enabled ? 0 : 1 }}">
                        <button class="hm-button-secondary">{{ $network->is_enabled ? 'Disable connector' : 'Enable connector' }}</button>
                    </form>
                </div>

                <form class="form-grid" method="POST" action="{{ route('admin.demand.networks.settings', $network) }}">
                    @csrf @method('PUT')
                    <label><input type="hidden" name="supports_direct_js" value="0"><input type="checkbox" name="supports_direct_js" value="1" @checked($network->supports_direct_js)> Supports Direct JS</label>
                    <label>Formats<select class="hm-input" name="supported_formats[]" multiple>@foreach(['DISPLAY','NATIVE','VIDEO','OUTSTREAM'] as $format)<option value="{{ $format }}" @selected(in_array($format, data_get($network->capabilities, 'supported_formats', []), true))>{{ $format }}</option>@endforeach</select></label>
                    <label>Integration modes<select class="hm-input" name="integration_modes[]" multiple>@foreach($modes as $mode)<option value="{{ $mode->value }}" @selected(in_array($mode->value, data_get($network->capabilities, 'integration_modes', []), true))>{{ $mode->value }}</option>@endforeach</select></label>
                    <label>Approved script origins<textarea class="hm-input" rows="3" name="script_origins[]">{{ implode("\n", $network->script_origins ?? []) }}</textarea></label>
                    <label>Health<select class="hm-input" name="operational_health">@foreach(['HEALTHY','DEGRADED','FAILED','UNKNOWN'] as $health)<option @selected(data_get($network->metadata, 'operational_health', 'UNKNOWN') === $health)>{{ $health }}</option>@endforeach</select></label>
                    <details class="full"><summary>Privacy capability evidence</summary>
                        <p class="muted">Use UNKNOWN until current operator or official evidence exists.</p>
                        <div class="form-grid">
                            @foreach(['tcf' => ['UNKNOWN','SUPPORTED','NOT_SUPPORTED'], 'gpp' => ['UNKNOWN','SUPPORTED','NOT_SUPPORTED'], 'gpc' => ['UNKNOWN','SUPPORTED','NOT_SUPPORTED']] as $field => $values)<label>{{ strtoupper($field) }}<select class="hm-input" name="privacy_{{ $field }}">@foreach($values as $value)<option @selected(data_get($network->privacy_capabilities, $field, 'UNKNOWN') === $value)>{{ $value }}</option>@endforeach</select></label>@endforeach
                            @foreach(['consent_before_request','storage','user_sync'] as $field)<label>{{ str($field)->replace('_', ' ')->headline() }}<select class="hm-input" name="privacy_{{ $field }}">@foreach(['UNKNOWN','REQUIRED','NOT_REQUIRED'] as $value)<option @selected(data_get($network->privacy_capabilities, $field, 'UNKNOWN') === $value)>{{ $value }}</option>@endforeach</select></label>@endforeach
                            <label>Official/operator evidence URL<input class="hm-input" type="url" name="privacy_evidence_url" value="{{ data_get($network->privacy_capabilities, 'evidence_url') }}"></label>
                            <label>Verified at<input class="hm-input" type="date" name="privacy_verified_at" value="{{ data_get($network->privacy_capabilities, 'verified_at') }}"></label>
                        </div>
                    </details>
                    <div class="full"><button class="hm-button-secondary">Save network policy</button></div>
                </form>

                <form class="form-stack" method="POST" action="{{ route('admin.demand.networks.direct-js', $network) }}">
                    @csrf @method('PATCH')
                    <input type="hidden" name="enabled" value="1">
                    <label>Runtime reason<input class="hm-input" name="reason" required minlength="5" value="Resume Direct Demand network runtime"></label>
                    <button class="hm-button-secondary">Ensure runtime ON</button>
                </form>
            </details>
        @endforeach
    </div>
</article>
@endsection
