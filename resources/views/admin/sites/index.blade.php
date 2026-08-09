@extends('layouts.admin')
@section('title', 'Website operations')
@section('heading', 'Website operations')
@section('content')
@if(filled($activeStatus ?? null))<p><x-status-badge :status="$activeStatus" /> <a class="section-anchor" href="{{ route('admin.sites.index') }}">Clear filter</a></p>@endif
<div class="table-wrap"><table><thead><tr><th>Website</th><th>Publisher</th><th>Domain</th><th>Status</th><th>Serving mode</th><th></th></tr></thead><tbody>
@forelse($sites as $site)<tr><td>{{ $site->display_name }}</td><td>{{ $site->publisher->display_name }}</td><td>{{ $site->primary_domain }}</td><td><x-status-badge :status="$site->status" /></td><td>{{ str($site->serving_mode->value)->replace('_', ' ')->headline() }}</td><td><a href="{{ route('admin.sites.show', $site) }}">Site 360</a></td></tr>@empty<tr><td colspan="6">No websites yet.</td></tr>@endforelse
</tbody></table></div>{{ $sites->links() }}
@endsection
