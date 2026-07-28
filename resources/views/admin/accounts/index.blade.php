@extends('layouts.admin')
@section('title', ucfirst($kind).'s')
@section('heading', ucfirst($kind).'s')
@section('navigation')<a href="{{ route('dashboard') }}">Overview</a><span class="active">{{ ucfirst($kind) }}s</span>@endsection
@section('content')
<a class="hm-button-primary button-link" href="{{ route('admin.'.$kind.'s.create') }}">New {{ $kind }}</a>
<div class="table-wrap"><table><thead><tr><th>Name</th><th>Organization</th><th>Status</th><th></th></tr></thead><tbody>@foreach($accounts as $account)<tr><td>{{ $account->display_name }}</td><td>{{ $account->organization->name }}</td><td>{{ $account->status->value }}</td><td><a href="{{ route('admin.'.$kind.'s.edit', $account) }}">Edit</a></td></tr>@endforeach</tbody></table></div>{{ $accounts->links() }}
@endsection
