@extends('layouts.admin')
@section('title', ucfirst($kind).'s')
@section('heading', ucfirst($kind).'s')
@section('content')
<div class="section-heading">
    <div>@if($kind === 'publisher' && filled($activeStatus ?? null))<x-status-badge :status="$activeStatus" />@endif</div>
    @if(auth()->user()->hasPermission($kind.'s.manage'))<a class="hm-button-primary button-link" href="{{ route('admin.'.$kind.'s.create') }}">New {{ $kind }}</a>@endif
</div>
<div class="table-wrap"><table><thead><tr><th>Name</th><th>Organization</th><th>Status</th><th>Actions</th></tr></thead><tbody>@foreach($accounts as $account)<tr><td>{{ $account->display_name }}</td><td>{{ $account->organization->name }}</td><td><x-status-badge :status="$account->status" /></td><td>@if($kind === 'publisher')<a href="{{ route('admin.publishers.show', $account) }}">Publisher 360</a>@endif @if(auth()->user()->hasPermission($kind.'s.manage')) @if($kind === 'publisher')·@endif <a href="{{ route('admin.'.$kind.'s.edit', $account) }}">Edit</a>@endif @if($kind === 'publisher' && auth()->user()->hasPermission('contracts.view')) · <a href="{{ route('admin.publishers.contracts.index', $account) }}">Commercial terms</a>@endif</td></tr>@endforeach</tbody></table></div>{{ $accounts->links() }}
@endsection
