@extends('layouts.admin')
@section('title', 'Audit Log')
@section('heading', 'Security & Audit — Audit Log')
@section('content')
<section>
    <h2>Audit Explorer</h2>
    <p class="muted">Immutable operational and security history. Sensitive values are redacted when recorded and cannot be revealed here.</p>
    <form method="GET" action="{{ route('admin.audit.index') }}" class="filter-grid">
        <label>From<input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
        <label>To<input type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
        <label>Actor<select name="actor_id"><option value="">All actors</option>@foreach($actors as $actor)<option value="{{ $actor->id }}" @selected(($filters['actor_id'] ?? '') === $actor->id)>{{ $actor->name }} · {{ $actor->email }}</option>@endforeach</select></label>
        <label>Organization<select name="organization_id"><option value="">All organizations</option>@foreach($organizations as $organization)<option value="{{ $organization->id }}" @selected(($filters['organization_id'] ?? '') === $organization->id)>{{ $organization->name }}</option>@endforeach</select></label>
        <label>Publisher<select name="publisher_id"><option value="">All publishers</option>@foreach($publishers as $publisher)<option value="{{ $publisher->id }}" @selected(($filters['publisher_id'] ?? '') === $publisher->id)>{{ $publisher->display_name ?: $publisher->legal_name }}</option>@endforeach</select></label>
        <label>Site<select name="site_id"><option value="">All sites</option>@foreach($sites as $site)<option value="{{ $site->id }}" @selected(($filters['site_id'] ?? '') === $site->id)>{{ $site->display_name }} · {{ $site->primary_domain }}</option>@endforeach</select></label>
        <label>Event<input name="event" value="{{ $filters['event'] ?? '' }}" placeholder="operations.control.changed"></label>
        <label>Route<input name="route" value="{{ $filters['route'] ?? '' }}" placeholder="admin.operations.controls"></label>
        <label>IP<input name="ip" value="{{ $filters['ip'] ?? '' }}" placeholder="203.0.113.10"></label>
        <label>Entity type<input name="auditable_type" value="{{ $filters['auditable_type'] ?? '' }}" placeholder="App\Models\Site"></label>
        <label>Entity ID<input name="auditable_id" value="{{ $filters['auditable_id'] ?? '' }}"></label>
        <label>Search<input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="event, entity, request, IP, user agent"></label>
        <label>Rows<select name="per_page">@foreach([25,50,100] as $size)<option value="{{ $size }}" @selected((int)($filters['per_page'] ?? 50) === $size)>{{ $size }}</option>@endforeach</select></label>
        <div><button type="submit">Apply filters</button> <a href="{{ route('admin.audit.index') }}">Clear</a></div>
    </form>
</section>
<section>
    <div class="table-wrap"><table>
        <thead><tr><th>Date / actor</th><th>Event</th><th>Organization / entity</th><th>Request</th><th>Before / after / metadata</th></tr></thead>
        <tbody>
        @forelse($logs as $log)
            <tr>
                <td>{{ $log->created_at }}<br><span class="muted">{{ $log->actor?->name ?? $log->actor_id ?? 'system' }}</span></td>
                <td><strong>{{ $log->event }}</strong></td>
                <td>{{ $log->organization?->name ?? $log->organization_id ?? 'platform' }}<br><code>{{ $log->auditable_type ?: '—' }}{{ $log->auditable_id ? ' · '.$log->auditable_id : '' }}</code></td>
                <td><span class="muted">{{ data_get($log->metadata, 'method') }} {{ data_get($log->metadata, 'route') }}</span><br>{{ $log->ip_address ?: '—' }}<br><span class="muted">{{ $log->user_agent ?: '—' }}</span>@if($log->request_id)<br><code>{{ $log->request_id }}</code>@endif</td>
                <td>
                    @if($log->old_values)<details><summary>Before</summary><pre>{{ json_encode($log->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>@endif
                    @if($log->new_values)<details><summary>After</summary><pre>{{ json_encode($log->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>@endif
                    @if($log->metadata)<details><summary>Metadata</summary><pre>{{ json_encode($log->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>@endif
                    @if(!$log->old_values && !$log->new_values && !$log->metadata)<span class="muted">No structured values.</span>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5">No audit events match these filters.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $logs->links() }}
</section>
@endsection
