@extends('layouts.admin')
@section('title', 'Native demand networks')
@section('heading', 'Native & alternative demand')
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Optional demand layer</p>
        <h2>HORUS_GAM remains primary</h2>
        <p>Enable direct JavaScript or GAM-managed native demand without changing publisher installation code. Credentials remain private and never enter static configuration.</p>
    </div>
    <div class="status-row">
        <span class="pill">{{ $networks->where('is_enabled', true)->count() }} connectors enabled</span>
        <span class="pill">{{ $accounts->total() }} accounts</span>
    </div>
</section>

<article>
    <div class="section-heading"><div><p class="eyebrow">Connector registry</p><h2>Available networks</h2></div></div>
    <div class="detail-grid">
        @foreach($networks as $network)
        <div class="domain-card">
            <div>
                <strong>{{ $network->name }}</strong>
                <span class="pill">{{ $network->code->value }}</span>
                <span class="pill">{{ $network->is_enabled ? 'ENABLED' : 'DISABLED' }}</span>
            </div>
            <p class="muted">{{ implode(' · ', $network->capabilities ?? []) }}</p>
            <form method="POST" action="{{ route('admin.demand.networks.toggle', $network) }}">@csrf @method('PATCH')
                <input type="hidden" name="is_enabled" value="{{ $network->is_enabled ? 0 : 1 }}">
                <button class="hm-button-secondary">{{ $network->is_enabled ? 'Disable connector' : 'Enable connector' }}</button>
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

<article>
    <div class="section-heading"><div><p class="eyebrow">Accounts</p><h2>Network accounts and reporting</h2></div></div>
    @forelse($accounts as $account)
    <div class="domain-card">
        <div class="section-heading">
            <div>
                <strong>{{ $account->name }}</strong>
                <span class="pill">{{ $account->network->code->value }}</span>
                <span class="pill">{{ $account->approval_status->value }}</span>
                <span class="pill">{{ $account->integration_mode->value }}</span>
                <span class="pill">{{ $account->is_enabled ? 'enabled' : 'disabled' }}</span>
            </div>
            <div class="status-row">
                <span class="pill">{{ data_get($summaries, $account->id.'.impressions', 0) }} impressions</span>
                <span class="pill">{{ data_get($summaries, $account->id.'.clicks', 0) }} clicks</span>
            </div>
        </div>

        <form class="form-grid" method="POST" action="{{ route('admin.demand.accounts.update', $account) }}">@csrf @method('PUT')
            <label>Name<input class="hm-input" name="name" value="{{ $account->name }}" required></label>
            <label>Scope<select class="hm-input" name="scope">@foreach($scopes as $scope)<option value="{{ $scope->value }}" @selected($account->scope === $scope)>{{ $scope->value }}</option>@endforeach</select></label>
            <label>Mode<select class="hm-input" name="integration_mode">@foreach($modes as $mode)<option value="{{ $mode->value }}" @selected($account->integration_mode === $mode)>{{ $mode->value }}</option>@endforeach</select></label>
            <label>Approval<select class="hm-input" name="approval_status">@foreach($statuses as $status)<option value="{{ $status->value }}" @selected($account->approval_status === $status)>{{ $status->value }}</option>@endforeach</select></label>
            <label>Account ID<input class="hm-input" name="account_identifier" value="{{ $account->account_identifier }}"></label>
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

        <section class="detail-grid">
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
