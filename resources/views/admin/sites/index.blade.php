@extends('layouts.admin')
@section('title', 'Website operations')
@section('heading', 'Website operations')
@section('navigation')<a href="{{ route('dashboard') }}">Overview</a><a href="{{ route('admin.publishers.index') }}">Publishers</a><a class="active" href="{{ route('admin.sites.index') }}">Websites</a>@endsection
@section('content')
<div class="table-wrap"><table><thead><tr><th>Website</th><th>Publisher</th><th>Domain</th><th>Status</th><th>Serving mode</th><th></th></tr></thead><tbody>
@forelse($sites as $site)<tr><td>{{ $site->display_name }}</td><td>{{ $site->publisher->display_name }}</td><td>{{ $site->primary_domain }}</td><td>{{ $site->status->value }}</td><td>{{ $site->serving_mode->value }}</td><td><a href="{{ route('admin.sites.show', $site) }}">Review</a></td></tr>@empty<tr><td colspan="6">No websites yet.</td></tr>@endforelse
</tbody></table></div>{{ $sites->links() }}
@endsection
