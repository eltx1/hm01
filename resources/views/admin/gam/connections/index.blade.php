@extends('layouts.admin')
@section('title', 'Google Ad Manager')
@section('heading', 'Google Ad Manager connections')
@section('content')
<section class="hero">
    <div><p class="eyebrow">GAM control plane</p><h2>One platform, multiple networks.</h2><p>HORUS_GAM remains the default. MCM partner and publisher networks can be selected per website without changing the publisher installation code.</p></div>
    <a class="hm-button-primary button-link" href="{{ route('admin.gam.connections.create') }}">Add connection</a>
</section>

<div class="metric-grid" style="margin-top:1rem">
    <article><span class="muted">Connections</span><strong class="metric">{{ $connections->total() }}</strong></article>
    <article><span class="muted">Primary HORUS_GAM</span><strong class="metric-small">{{ $primaryHorus?->name ?? 'Not set' }}</strong></article>
    <article><span class="muted">Unresolved Horus sites</span><strong class="metric">{{ $unresolvedHorusSites }}</strong></article>
</div>

<div class="table-wrap">
<table>
    <thead><tr><th>Name</th><th>Type</th><th>Network</th><th>Health</th><th>Primary</th><th>Sites</th><th>Operations</th><th></th></tr></thead>
    <tbody>
    @forelse($connections as $connection)
        <tr>
            <td><strong>{{ $connection->name }}</strong><br><span class="muted">{{ $connection->driver }}</span></td>
            <td>{{ $connection->type->value }}</td>
            <td>{{ $connection->network_code ?: 'Pending test' }}</td>
            <td><span class="pill">{{ $connection->health_status->value }}</span></td>
            <td>{{ $connection->is_primary ? 'Yes' : 'No' }}</td>
            <td>{{ $connection->sites_count }}</td>
            <td>{{ $connection->operations_count }}</td>
            <td><a class="text-link" href="{{ route('admin.gam.connections.show', $connection) }}">Manage</a></td>
        </tr>
    @empty
        <tr><td colspan="8" class="muted">No GAM connections have been configured.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
{{ $connections->links() }}
@endsection
