@extends('layouts.admin')
@section('title', 'Websites')
@section('heading', 'Websites')
@section('content')
<section class="hero dashboard-hero">
    <div>
        <p class="eyebrow">Your inventory</p>
        <h2>Manage each website from one place.</h2>
        <p>Open a website to see its current status, required setup, monetization health, ads.txt instructions and installation codes.</p>
    </div>
    @if(auth()->user()->hasPermission('sites.manage'))
        <a class="hm-button-primary button-link" href="{{ route('publisher.sites.create') }}">Add website</a>
    @endif
</section>

@if($sites->count() === 0)
    <x-empty-state title="No websites yet" description="Add your first website to begin verification and monetization setup.">
        @if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-primary button-link" href="{{ route('publisher.sites.create') }}">Add your first website</a>@endif
    </x-empty-state>
@else
    <div class="site-card-grid">
        @foreach($sites as $site)
            <a class="site-card" href="{{ route('publisher.sites.show', $site) }}">
                <div class="workspace-heading">
                    <div><p class="eyebrow">{{ $site->primary_domain }}</p><h2>{{ $site->display_name }}</h2></div>
                    <x-status-badge :status="$site->status" />
                </div>
                <div class="site-card-meta">
                    <span><strong>Serving</strong>{{ str($site->serving_mode->value)->replace('_', ' ')->headline() }}</span>
                    <span><strong>Country</strong>{{ $site->country ?: '—' }}</span>
                    <span><strong>Category</strong>{{ $site->content_category ?: '—' }}</span>
                </div>
                <span class="section-anchor">Open website →</span>
            </a>
        @endforeach
    </div>
    {{ $sites->links() }}
@endif
@endsection
