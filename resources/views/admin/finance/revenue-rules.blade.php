@extends('layouts.admin')
@section('title', 'Revenue Rules')
@section('heading', 'Revenue Rules')
@section('content')
@include('admin.finance._tabs')
<section class="hero">
    <div>
        <p class="eyebrow">Commercial revenue control</p>
        <h2>Set the default share, then override only where needed.</h2>
        <p>Priority is automatic: Campaign → Publisher + demand → Demand source → Website → Publisher → Global. A more specific rule always wins, and every change keeps immutable history.</p>
    </div>
</section>

@php
    $previewRequested = request()->boolean('preview');
    $previewError = null;
    $previewVersion = null;
    $previewRule = null;
    $previewDate = (string) request('preview_date', now()->toDateString());
    $previewCurrency = strtoupper(trim((string) request('preview_currency', 'USD')));

    if ($previewRequested) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $previewDate) !== 1) {
            $previewError = 'Choose a valid preview date.';
        } elseif ($previewCurrency !== '' && preg_match('/^[A-Z]{3}$/', $previewCurrency) !== 1) {
            $previewError = 'Currency must be a three-letter code such as USD.';
        } else {
            $previewPublisherId = trim((string) request('preview_publisher_id'));
            $previewSiteId = trim((string) request('preview_site_id'));
            $previewDemandId = trim((string) request('preview_demand_id'));
            $previewCampaignId = trim((string) request('preview_campaign_id'));

            if ($previewPublisherId === '' && $previewSiteId !== '') {
                $previewPublisherId = (string) ($sites->firstWhere('id', $previewSiteId)?->publisher_id ?? '');
            }

            try {
                $previewVersion = app(\App\Services\Reporting\RevenueRuleService::class)->resolve($previewDate, array_filter([
                    'publisher_id' => $previewPublisherId,
                    'site_id' => $previewSiteId,
                    'report_source_id' => $previewDemandId,
                    'demand_network_id' => $previewDemandId,
                    'campaign_id' => $previewCampaignId,
                ], fn ($value) => $value !== ''), $previewCurrency !== '' ? $previewCurrency : null);
                if ($previewVersion->exists && $previewVersion->revenue_rule_id) {
                    $previewRule = $rules->firstWhere('id', $previewVersion->revenue_rule_id);
                }
            } catch (\Throwable $exception) {
                report($exception);
                $previewError = 'The preview could not be resolved with the selected context.';
            }
        }
    }
@endphp

<article class="workspace-section">
    <div class="workspace-heading">
        <div><p class="eyebrow">Share preview</p><h2>Which revenue share will actually apply?</h2><p>Use the same resolver as financial reporting before you activate traffic or change a commercial rule. This preview is read-only and changes nothing.</p></div>
    </div>
    <form method="GET" action="{{ route('admin.finance.revenue-rules.index') }}" class="form-grid">
        <input type="hidden" name="preview" value="1">
        <label>Publisher
            <select class="hm-input" name="preview_publisher_id"><option value="">Any / not selected</option>@foreach($publishers as $publisher)<option value="{{ $publisher->id }}" @selected(request('preview_publisher_id') === $publisher->id)>{{ $publisher->display_name }}</option>@endforeach</select>
        </label>
        <label>Website
            <select class="hm-input" name="preview_site_id"><option value="">Any / not selected</option>@foreach($sites as $site)<option value="{{ $site->id }}" @selected(request('preview_site_id') === $site->id)>{{ $site->display_name }} · {{ $site->publisher?->display_name }}</option>@endforeach</select>
            <span class="muted">If Publisher is blank, the Website's Publisher is inferred automatically.</span>
        </label>
        <label>Demand source
            <select class="hm-input" name="preview_demand_id"><option value="">Any / not selected</option><optgroup label="Reporting sources">@foreach($reportSources as $source)<option value="{{ $source->id }}" @selected(request('preview_demand_id') === $source->id)>{{ $source->name }}</option>@endforeach</optgroup><optgroup label="Demand networks">@foreach($demandNetworks as $network)<option value="{{ $network->id }}" @selected(request('preview_demand_id') === $network->id)>{{ $network->name }}</option>@endforeach</optgroup></select>
        </label>
        <label>Campaign
            <select class="hm-input" name="preview_campaign_id"><option value="">Any / not selected</option>@foreach($campaigns as $campaign)<option value="{{ $campaign->id }}" @selected(request('preview_campaign_id') === $campaign->id)>{{ $campaign->name }}</option>@endforeach</select>
        </label>
        <label>Date<input class="hm-input" type="date" name="preview_date" value="{{ $previewDate }}" required></label>
        <label>Currency<input class="hm-input" name="preview_currency" maxlength="3" value="{{ $previewCurrency }}" placeholder="USD"></label>
        <button class="hm-button-primary">Preview effective share</button>
    </form>

    @if($previewRequested)
        @if($previewError)
            <p class="error">{{ $previewError }}</p>
        @elseif($previewVersion)
            <div class="summary-grid">
                <div><strong>Winning rule</strong><span>{{ $previewRule?->name ?: 'System fallback' }}</span></div>
                <div><strong>Target</strong><span>{{ $previewRule ? ($scopeLabels[$previewRule->id] ?? 'Resolved target') : 'Default reporting configuration' }}</span></div>
                <div><strong>Publisher</strong><span>{{ number_format($previewVersion->publisher_share_bp / 100, 2) }}%</span></div>
                <div><strong>Horus Media</strong><span>{{ number_format($previewVersion->horus_share_bp / 100, 2) }}%</span></div>
                <div><strong>MCM partner</strong><span>{{ number_format($previewVersion->mcm_partner_share_bp / 100, 2) }}%</span></div>
                <div><strong>Version</strong><span>{{ $previewVersion->version === 0 ? 'System fallback' : 'v'.$previewVersion->version }}</span></div>
                <div><strong>Effective</strong><span>{{ $previewVersion->effective_from->toDateString() }} — {{ $previewVersion->effective_to?->toDateString() ?: 'Open' }}</span></div>
                <div><strong>Currency</strong><span>{{ $previewVersion->currency ?: ($previewCurrency ?: 'Any') }}</span></div>
            </div>
            @if($previewRule)<p class="muted">Resolved by {{ str($previewRule->scope_type->value)->replace('_', ' ')->headline() }} specificity, then explicit priority. {{ $previewVersion->reason ? 'Reason: '.$previewVersion->reason : '' }}</p>@else<p class="muted">No stored rule matched this context, so the configured system fallback is shown.</p>@endif
        @endif
    @endif
</article>

@if(auth()->user()->hasPermission('finance.revenue_rules.manage'))
<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">New rule</p><h2>Create a revenue-share rule</h2><p>Choose the scope and its matching named target. You never need to copy database IDs.</p></div></div>
    <form method="post" action="{{ route('admin.finance.revenue-rules.store') }}" class="form-grid">@csrf
        <label>Rule name<input class="hm-input" name="name" required maxlength="255" placeholder="Example: Lordai · AdX share"></label>
        <label>Applies to
            <select class="hm-input" name="scope_type" required>
                @foreach($ruleScopes as $scope)<option value="{{ $scope->value }}">{{ match($scope) {
                    \App\Enums\RevenueRuleScope::Global => 'Everyone (global fallback)',
                    \App\Enums\RevenueRuleScope::Publisher => 'One publisher',
                    \App\Enums\RevenueRuleScope::Website => 'One website',
                    \App\Enums\RevenueRuleScope::DemandSource => 'One demand source (all publishers)',
                    \App\Enums\RevenueRuleScope::PublisherDemandSource => 'One publisher + one demand source',
                    \App\Enums\RevenueRuleScope::Campaign => 'One direct campaign',
                } }}</option>@endforeach
            </select>
        </label>
        <label>Publisher
            <select class="hm-input" name="publisher_scope_id"><option value="">Not used for this scope</option>@foreach($publishers as $publisher)<option value="{{ $publisher->id }}">{{ $publisher->display_name }}</option>@endforeach</select>
            <span class="muted">Choose for Publisher or Publisher + demand.</span>
        </label>
        <label>Website
            <select class="hm-input" name="website_scope_id"><option value="">Not used for this scope</option>@foreach($sites as $site)<option value="{{ $site->id }}">{{ $site->display_name }} · {{ $site->publisher?->display_name }}</option>@endforeach</select>
        </label>
        <label>Demand source
            <select class="hm-input" name="demand_scope_id"><option value="">Not used for this scope</option><optgroup label="Reporting sources">@foreach($reportSources as $source)<option value="{{ $source->id }}">{{ $source->name }}</option>@endforeach</optgroup><optgroup label="Demand networks">@foreach($demandNetworks as $network)<option value="{{ $network->id }}">{{ $network->name }}</option>@endforeach</optgroup></select>
            <span class="muted">Choose for Demand source or Publisher + demand.</span>
        </label>
        <label>Campaign
            <select class="hm-input" name="campaign_scope_id"><option value="">Not used for this scope</option>@foreach($campaigns as $campaign)<option value="{{ $campaign->id }}">{{ $campaign->name }}</option>@endforeach</select>
        </label>
        <label>Effective from<input class="hm-input" type="date" name="effective_from" value="{{ now()->toDateString() }}" required></label>
        <label>Effective to (optional)<input class="hm-input" type="date" name="effective_to"></label>
        <label>Currency (optional)<input class="hm-input" name="currency" maxlength="3" placeholder="USD"></label>
        <label>Publisher %<input class="hm-input" type="number" name="publisher_share_percent" min="0" max="100" step="0.01" value="70" required></label>
        <label>Horus Media %<input class="hm-input" type="number" name="horus_share_percent" min="0" max="100" step="0.01" value="30" required></label>
        <label>MCM partner %<input class="hm-input" type="number" name="mcm_partner_share_percent" min="0" max="100" step="0.01" value="0"></label>
        <input type="hidden" name="priority" value="0">
        <label class="full">Reason / note<textarea class="hm-input" name="reason" maxlength="10000" placeholder="Commercial approval or source reference"></textarea></label>
        <p class="muted full">The three percentages must total exactly 100%.</p>
        <button class="hm-button-primary">Create rule</button>
    </form>
</article>
@endif

<section class="workspace-section compact-list">
@forelse($rules as $rule)
<article>
    <div class="workspace-heading">
        <div><p class="eyebrow">{{ str($rule->scope_type->value)->replace('_', ' ')->headline() }}</p><h2>{{ $rule->name }}</h2><p>{{ $scopeLabels[$rule->id] }} · {{ $rule->is_active ? 'Active' : 'Inactive' }} · Created by {{ $rule->creator?->name ?: 'System' }}</p></div>
        <x-status-badge :status="$rule->is_active ? 'ACTIVE' : 'INACTIVE'" />
    </div>
    <div class="table-wrap"><table><thead><tr><th>Version</th><th>Effective</th><th>Currency</th><th>Publisher</th><th>Horus</th><th>MCM</th><th>Creator</th><th>Reason</th></tr></thead><tbody>
        @foreach($rule->versions->sortByDesc('version') as $version)<tr><td>{{ $version->version }}</td><td>{{ $version->effective_from->toDateString() }} — {{ $version->effective_to?->toDateString() ?: 'Open' }}</td><td>{{ $version->currency ?: 'Any' }}</td><td>{{ number_format($version->publisher_share_bp / 100, 2) }}%</td><td>{{ number_format($version->horus_share_bp / 100, 2) }}%</td><td>{{ number_format($version->mcm_partner_share_bp / 100, 2) }}%</td><td>{{ $version->creator?->name ?: 'System' }}</td><td>{{ $version->reason ?: '—' }}</td></tr>@endforeach
    </tbody></table></div>
    @if(auth()->user()->hasPermission('finance.revenue_rules.manage'))
    <details><summary class="text-link">Schedule a share change</summary>
        <form method="post" action="{{ route('admin.finance.revenue-rules.version', $rule) }}" class="form-grid">@csrf
            <label>Effective from<input class="hm-input" type="date" name="effective_from" required></label>
            <label>Effective to (optional)<input class="hm-input" type="date" name="effective_to"></label>
            <label>Currency<input class="hm-input" name="currency" maxlength="3" value="{{ $rule->currentVersion?->currency }}"></label>
            <label>Publisher %<input class="hm-input" type="number" name="publisher_share_percent" min="0" max="100" step="0.01" value="{{ $rule->currentVersion ? $rule->currentVersion->publisher_share_bp / 100 : 70 }}" required></label>
            <label>Horus Media %<input class="hm-input" type="number" name="horus_share_percent" min="0" max="100" step="0.01" value="{{ $rule->currentVersion ? $rule->currentVersion->horus_share_bp / 100 : 30 }}" required></label>
            <label>MCM partner %<input class="hm-input" type="number" name="mcm_partner_share_percent" min="0" max="100" step="0.01" value="{{ $rule->currentVersion ? $rule->currentVersion->mcm_partner_share_bp / 100 : 0 }}" required></label>
            <label class="full">Required reason<textarea class="hm-input" name="reason" required maxlength="10000"></textarea></label>
            <button class="hm-button-primary">Save new version</button>
        </form>
    </details>
    @endif
</article>
@empty<article><p class="muted">No revenue rules exist.</p></article>@endforelse
</section>
@endsection