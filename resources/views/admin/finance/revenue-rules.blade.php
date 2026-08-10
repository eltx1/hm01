@extends('layouts.admin')
@section('title', 'Revenue Rules')
@section('heading', 'Revenue Rules')
@section('content')
@include('admin.finance._tabs')
<section class="hero"><div><p class="eyebrow">Versioned economics</p><h2>Effective rules without retroactive mutation</h2><p>Every share change creates a new version. Finalized reporting retains the exact rule version used for its calculation.</p></div></section>
@if(auth()->user()->hasPermission('finance.revenue_rules.manage'))
<article class="workspace-section"><div class="workspace-heading"><div><p class="eyebrow">New rule</p><h2>Create effective version 1</h2></div></div><form method="post" action="{{ route('admin.finance.revenue-rules.store') }}" class="form-grid">@csrf
    <label>Name<input class="hm-input" name="name" required maxlength="255"></label>
    <label>Scope<select name="scope_type" required>@foreach($ruleScopes as $scope)<option value="{{ $scope->value }}">{{ str($scope->value)->headline() }}</option>@endforeach</select></label>
    <label>Target ID<input class="hm-input" name="scope_id" maxlength="64"><span class="muted">Required except for global rules; use a Publisher, website, demand source, or campaign ID.</span></label>
    <label>Effective from<input class="hm-input" type="date" name="effective_from" required></label>
    <label>Effective to<input class="hm-input" type="date" name="effective_to"></label>
    <label>Currency<input class="hm-input" name="currency" maxlength="3"></label>
    <label>Publisher share (bp)<input class="hm-input" type="number" name="publisher_share_bp" min="0" max="10000" required></label>
    <label>Horus share (bp)<input class="hm-input" type="number" name="horus_share_bp" min="0" max="10000" required></label>
    <label>MCM share (bp)<input class="hm-input" type="number" name="mcm_partner_share_bp" min="0" max="10000" value="0"></label>
    <label>Priority<input class="hm-input" type="number" name="priority" min="0" max="100000" value="0"></label>
    <label class="full">Reason<textarea class="hm-input" name="reason" maxlength="10000"></textarea></label><button class="hm-button-primary">Create rule</button>
</form></article>
@endif
<section class="workspace-section compact-list">
@forelse($rules as $rule)
<article><div class="workspace-heading"><div><p class="eyebrow">{{ $rule->scope_type->value }} · Priority {{ $rule->priority }}</p><h2>{{ $rule->name }}</h2><p>{{ $scopeLabels[$rule->id] }} · {{ $rule->is_active ? 'Active' : 'Inactive' }} · Created by {{ $rule->creator?->name ?: 'System' }}</p></div><x-status-badge :status="$rule->is_active ? 'ACTIVE' : 'INACTIVE'" /></div>
    <div class="table-wrap"><table><thead><tr><th>Version</th><th>Effective</th><th>Currency</th><th>Publisher</th><th>Horus</th><th>MCM</th><th>Creator</th><th>Reason</th></tr></thead><tbody>@foreach($rule->versions->sortByDesc('version') as $version)<tr><td>{{ $version->version }}</td><td>{{ $version->effective_from->toDateString() }} — {{ $version->effective_to?->toDateString() ?: 'Open' }}</td><td>{{ $version->currency ?: 'Any' }}</td><td>{{ $version->publisher_share_bp }} bp</td><td>{{ $version->horus_share_bp }} bp</td><td>{{ $version->mcm_partner_share_bp }} bp</td><td>{{ $version->creator?->name ?: 'System' }}</td><td>{{ $version->reason ?: '—' }}</td></tr>@endforeach</tbody></table></div>
    @if(auth()->user()->hasPermission('finance.revenue_rules.manage'))<details><summary class="text-link">Create next version</summary><form method="post" action="{{ route('admin.finance.revenue-rules.version', $rule) }}" class="form-grid">@csrf<label>Effective from<input class="hm-input" type="date" name="effective_from" required></label><label>Effective to<input class="hm-input" type="date" name="effective_to"></label><label>Currency<input class="hm-input" name="currency" maxlength="3" value="{{ $rule->currentVersion?->currency }}"></label><label>Publisher share (bp)<input class="hm-input" type="number" name="publisher_share_bp" min="0" max="10000" value="{{ $rule->currentVersion?->publisher_share_bp }}" required></label><label>Horus share (bp)<input class="hm-input" type="number" name="horus_share_bp" min="0" max="10000" value="{{ $rule->currentVersion?->horus_share_bp }}" required></label><label>MCM share (bp)<input class="hm-input" type="number" name="mcm_partner_share_bp" min="0" max="10000" value="{{ $rule->currentVersion?->mcm_partner_share_bp }}" required></label><label class="full">Required reason<textarea class="hm-input" name="reason" required maxlength="10000"></textarea></label><button class="hm-button-primary">Create immutable version</button></form></details>@endif
</article>
@empty<article><p class="muted">No revenue rules exist.</p></article>@endforelse
</section>
@endsection
