@extends('layouts.admin')
@section('title', 'Website operations')
@section('heading', 'Website operations')
@section('content')
<form method="GET" action="{{ route('admin.sites.index') }}" class="inline-form" aria-label="Filter website health evidence">
    <label>Website state<select class="hm-input" name="status"><option value="">All states</option>@foreach(\App\Enums\SiteStatus::cases() as $state)<option value="{{ $state->value }}" @selected($activeStatus === $state->value)>{{ str($state->value)->replace('_', ' ')->headline() }}</option>@endforeach</select></label>
    <label>Stored evidence<select class="hm-input" name="health"><option value="">All evidence</option><option value="findings" @selected($healthFilter === 'findings')>Has stored findings (including old records)</option><option value="stale" @selected($healthFilter === 'stale')>Has stale or invalid observation times</option><option value="missing" @selected($healthFilter === 'missing')>No stored checks</option></select></label>
    <button class="hm-button-secondary" type="submit">Filter sites</button>
    @if($healthFilter || $activeStatus)<a href="{{ route('admin.sites.index') }}">Clear all filters</a>@endif
</form>
@if(filled($activeStatus ?? null))<p><x-status-badge :status="$activeStatus" /> <a class="section-anchor" href="{{ route('admin.sites.index', ['health' => $healthFilter]) }}">Clear state filter</a></p>@endif
<p class="muted">Recorded monetization checks run every 15 minutes. Evidence older than one hour is stale. These checks do not confirm ad rendering or classify all website traffic.</p>
<div class="table-wrap"><table><thead><tr><th>Website</th><th>Publisher</th><th>Domain</th><th>Status</th><th>Serving mode</th><th>Recorded health evidence</th><th></th></tr></thead><tbody>
@forelse($sites as $site)
<tr>
    <td>{{ $site->display_name }}</td>
    <td>
        @if(auth()->user()->hasPermission('publishers.view'))
            <a
                class="section-anchor"
                href="{{ route('admin.publishers.show', $site->publisher) }}"
                aria-label="Open Publisher 360 for {{ $site->publisher->display_name }}"
                title="Open Publisher 360"
            >{{ $site->publisher->display_name }}</a>
        @else
            {{ $site->publisher->display_name }}
        @endif
    </td>
    <td>{{ $site->primary_domain }}</td>
    <td><x-status-badge :status="$site->status" /></td>
    <td>{{ str($site->serving_mode->value)->replace('_', ' ')->headline() }}</td>
    <td data-health-evidence="{{ $healthEvidence[$site->id]['state'] }}">
        <strong>{{ $healthEvidence[$site->id]['label'] }}</strong>
        <small>{{ $healthEvidence[$site->id]['findings'] }} finding(s) in {{ $healthEvidence[$site->id]['checks'] }} stored checks</small>
        <small>Oldest observation (UTC): {{ $healthEvidence[$site->id]['oldest_observation_utc'] ?? 'Not recorded' }}</small>
        <a href="{{ route('admin.sites.show', $site) }}#monetization-health">Review site evidence</a>
    </td>
    <td><div class="status-row">@if(auth()->user()->hasPermission('reporting.admin.view'))<a class="hm-button-secondary" href="{{ route('admin.reporting.websites.show', $site) }}">Reports</a>@endif<a href="{{ route('admin.sites.show', $site) }}">Site 360</a>@if(auth()->user()->hasPermission('demand.manage'))<a class="hm-button-secondary button-link" href="{{ route('admin.demand.quick.manage', ['site' => $site->id]) }}">Manage ads</a>@endif</div></td>
</tr>
@empty
<tr><td colspan="7">No websites yet.</td></tr>
@endforelse
</tbody></table></div>{{ $sites->links() }}
@endsection
