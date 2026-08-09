@extends('layouts.admin')
@section('title', 'Websites')
@section('heading', 'Publisher websites')
@section('content')
@if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-primary button-link" href="{{ route('publisher.sites.create') }}">Add website</a>@endif
<div class="table-wrap"><table><thead><tr><th>Website</th><th>Domain</th><th>Status</th><th>Serving mode</th><th></th></tr></thead><tbody>
@forelse($sites as $site)<tr><td>{{ $site->display_name }}</td><td>{{ $site->primary_domain }}</td><td><span class="pill">{{ $site->status->value }}</span></td><td>{{ $site->serving_mode->value }}</td><td><a href="{{ route('publisher.sites.show', $site) }}">View</a></td></tr>@empty<tr><td colspan="5">No websites registered yet.</td></tr>@endforelse
</tbody></table></div>{{ $sites->links() }}
@endsection
