@extends('layouts.admin')
@section('title', 'Configuration history')
@section('heading', 'Configuration history')
@section('content')
<section><h2>{{ $site->display_name }}</h2><a href="{{ route('admin.sites.show', $site) }}">Return to website</a>
<p>Confirmed production version: {{ $confirmed?->version ?? 'No confirmation evidence' }}. Queued versions are not treated as live.</p>
<p class="muted">Read-only comparison of selected fields against confirmed production. Unknown values are not disabled settings. This is not a rollback preview or approval: restoring an old version must preserve current protection and validate current provider eligibility.</p></section>
@forelse($rows as $row)
<section><h2>Version {{ $row['version'] }} @if($row['confirmed']) · Confirmed production @endif</h2>
<p>Version state: {{ $row['status'] }} · Delivery: {{ $row['delivery'] }} · Created (UTC): {{ $row['created_at'] ?? 'Unknown' }}</p>
@if(!$row['comparison']['baseline_available'])<p>No confirmed baseline; differences are unavailable.</p>@endif
<div class="table-wrap"><table><thead><tr><th>Field</th><th>Stored value</th><th>Compared with confirmed production</th></tr></thead><tbody>
@foreach($row['comparison']['fields'] as $label => $value)<tr><td>{{ $label }}</td><td>{{ $value === null ? 'Unknown' : (is_bool($value) ? ($value ? 'Enabled' : 'Disabled') : $value) }}</td><td>{{ !$row['comparison']['baseline_available'] ? 'Unavailable' : (in_array($label, $row['comparison']['changed'], true) ? 'Different' : 'Same recorded value') }}</td></tr>@endforeach
</tbody></table></div></section>
@empty<section><p>No production configuration history recorded.</p></section>@endforelse
{{ $versions->links() }}
@endsection
