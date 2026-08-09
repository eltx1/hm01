@extends('layouts.admin')
@section('title', 'Organizations')
@section('heading', 'Organizations')
@section('content')
<a class="hm-button-primary button-link" href="{{ route('admin.organizations.create') }}">New organization</a>
<div class="table-wrap"><table><thead><tr><th>Name</th><th>Type</th><th>Status</th><th></th></tr></thead><tbody>@foreach($organizations as $organization)<tr><td>{{ $organization->name }}</td><td>{{ $organization->type->value }}</td><td>{{ $organization->status->value }}</td><td><a href="{{ route('admin.organizations.edit', $organization) }}">Edit</a></td></tr>@endforeach</tbody></table></div>{{ $organizations->links() }}
@endsection
