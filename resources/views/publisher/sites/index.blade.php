@extends('layouts.admin')
@section('title', 'Websites')
@section('heading', 'Publisher websites')
@section('content')
<div class="section-heading">
    <div><p class="muted">Manage websites connected to your Horus Media publisher workspace.</p></div>
    @if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-primary" href="{{ route('publisher.sites.create') }}">Add website</a>@endif
</div>

@if($sites->count() === 0)
    <x-empty-state title="No websites yet" description="Add your first website to begin domain verification, onboarding, and monetization setup.">
        @if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-primary" href="{{ route('publisher.sites.create') }}">Add your first website</a>@endif
    </x-empty-state>
@else
    <div class="table-wrap" role="region" aria-label="Publisher websites" tabindex="0">
        <table>
            <thead><tr><th scope="col">Website</th><th scope="col">Domain</th><th scope="col">Status</th><th scope="col">Serving mode</th><th scope="col">Action</th></tr></thead>
            <tbody>
            @foreach($sites as $site)
                <tr>
                    <td>{{ $site->display_name }}</td>
                    <td>{{ $site->primary_domain }}</td>
                    <td><x-status-badge :status="$site->status" /></td>
                    <td>{{ str($site->serving_mode->value)->replace('_', ' ')->headline() }}</td>
                    <td><a class="section-anchor" href="{{ route('publisher.sites.show', $site) }}">View website</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    {{ $sites->links() }}
@endif
@endsection
