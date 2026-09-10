@extends('layouts.admin')
@section('title', 'Add Direct Demand Account')
@section('heading', 'Add Direct Demand Account')
@section('content')
<section class="hero workspace-section">
    <div>
        <p class="eyebrow">Direct Demand</p>
        <h2>Create demand account</h2>
        <p>Create the provider relationship first, then assign it to websites and placements. Horus Publisher identity and provider-issued account identifiers are deliberately separate.</p>
        <div class="status-row">
            <a class="hm-button-secondary button-link" href="{{ route('admin.demand.index') }}">← Back to accounts</a>
        </div>
    </div>
</section>

<article class="workspace-section">
    <div class="workspace-heading">
        <div><p class="eyebrow">Account identity</p><h2>Provider and ownership</h2></div>
    </div>

    <form class="form-grid" method="POST" action="{{ route('admin.demand.accounts.store') }}" id="demand-account-create">
        @csrf
        <label>Network
            <select class="hm-input" name="demand_network_id" required>
                @foreach($networks as $network)
                    <option value="{{ $network->id }}" @selected(old('demand_network_id') === $network->id)>{{ $network->name }} · {{ $network->code->value }}</option>
                @endforeach
            </select>
        </label>

        <label>Account name
            <input class="hm-input" name="name" value="{{ old('name') }}" required placeholder="Google AdX Manual - lordai.net">
        </label>

        <label>Scope
            <select class="hm-input" name="scope" id="demand-scope" required>
                @foreach($scopes as $scope)
                    <option value="{{ $scope->value }}" @selected(old('scope', 'HORUS_MEDIA') === $scope->value)>{{ str($scope->value)->replace('_', ' ')->headline() }}</option>
                @endforeach
            </select>
            <span class="muted">Choose who owns this demand relationship inside Horus.</span>
        </label>

        <label id="publisher-assignment">Horus Publisher
            <select class="hm-input" name="publisher_id" id="publisher-id">
                <option value="">Select publisher</option>
                @foreach($publishers as $publisher)
                    <option value="{{ $publisher->id }}" @selected(old('publisher_id') === $publisher->id)>{{ $publisher->display_name }} · {{ $publisher->id }}</option>
                @endforeach
            </select>
            <span class="muted">Required only for Publisher scope. This links the demand account to the internal Horus Publisher record.</span>
        </label>

        <label id="partner-assignment">MCM partner organization
            <select class="hm-input" name="partner_organization_id" id="partner-id">
                <option value="">Select partner</option>
                @foreach($partners as $partner)
                    <option value="{{ $partner->id }}" @selected(old('partner_organization_id') === $partner->id)>{{ $partner->name }} · {{ $partner->id }}</option>
                @endforeach
            </select>
            <span class="muted">Required only for MCM Partner scope.</span>
        </label>

        <label>Integration mode
            <select class="hm-input" name="integration_mode" required>
                @foreach($modes as $mode)
                    <option value="{{ $mode->value }}" @selected(old('integration_mode', 'MANUAL_TAG') === $mode->value)>{{ $mode->value }}</option>
                @endforeach
            </select>
        </label>

        <label>Approval status
            <select class="hm-input" name="approval_status">
                @foreach($statuses as $status)
                    <option value="{{ $status->value }}" @selected(old('approval_status') === $status->value)>{{ $status->value }}</option>
                @endforeach
            </select>
        </label>

        <label>Provider account identifier <span class="muted">(optional)</span>
            <input class="hm-input" name="account_identifier" value="{{ old('account_identifier') }}" placeholder="Provider-issued public account ID">
            <span class="muted">This is issued by Google/MGID/Taboola/etc. It is NOT the Horus Publisher ID. Leave it blank for a manual Google tag unless Google supplied a separate account identifier you need to record.</span>
        </label>

        <label>Reporting method
            <select class="hm-input" name="reporting_method">
                <option value="">Not configured</option>
                <option value="API" @selected(old('reporting_method') === 'API')>API</option>
                <option value="CSV" @selected(old('reporting_method') === 'CSV')>CSV</option>
            </select>
        </label>

        <label>Default render timeout ms
            <input class="hm-input" type="number" min="500" max="10000" name="default_render_timeout_ms" value="{{ old('default_render_timeout_ms', 2500) }}">
        </label>

        <label class="full">Approved script origins <span class="muted">(optional)</span>
            <textarea class="hm-input" rows="3" name="approved_script_origins_text" placeholder="https://securepubads.g.doubleclick.net">{{ old('approved_script_origins_text') }}</textarea>
            <span class="muted">One HTTPS origin per line. Empty is valid. For Google GPT, start with https://securepubads.g.doubleclick.net and add only origins actually required by the reviewed tag.</span>
        </label>

        <label>Revenue share %
            <input class="hm-input" type="number" step="0.001" min="0" max="100" name="revenue_share_percent" value="{{ old('revenue_share_percent', 0) }}" required>
        </label>

        <label>Fallback priority
            <input class="hm-input" type="number" min="0" max="10000" name="fallback_priority" value="{{ old('fallback_priority', 100) }}" required>
        </label>

        <label class="full">Non-secret configuration JSON
            <textarea class="hm-input" rows="7" name="configuration_json">{{ old('configuration_json', '{}') }}</textarea>
            <span class="muted">Only public configuration belongs here. Empty JSON object <code>{}</code> is valid. Never paste API keys, OAuth secrets or tokens.</span>
        </label>

        <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled', 1))> Enabled</label>
        <label><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', 0))> Default account for this network</label>

        <div class="full status-row">
            <button class="hm-button-primary">Create demand account</button>
            <a class="hm-button-secondary button-link" href="{{ route('admin.demand.index') }}">Cancel</a>
        </div>
    </form>
</article>

<script>
(() => {
    const scope = document.getElementById('demand-scope');
    const publisherWrap = document.getElementById('publisher-assignment');
    const publisher = document.getElementById('publisher-id');
    const partnerWrap = document.getElementById('partner-assignment');
    const partner = document.getElementById('partner-id');

    const refresh = () => {
        const isPublisher = scope.value === 'PUBLISHER';
        const isPartner = scope.value === 'MCM_PARTNER';
        publisherWrap.hidden = !isPublisher;
        partnerWrap.hidden = !isPartner;
        publisher.required = isPublisher;
        partner.required = isPartner;
        if (!isPublisher) publisher.value = '';
        if (!isPartner) partner.value = '';
    };

    scope.addEventListener('change', refresh);
    refresh();
})();
</script>
@endsection
