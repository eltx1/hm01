@extends('layouts.admin')
@section('title', 'Websites')
@section('heading', 'Websites')
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Your websites</p>
        <h2>Manage every site from one clear list.</h2>
        <p>Open a website to finish setup, check its status, review ads.txt, or see monetization details.</p>
    </div>
    @if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-primary button-link" href="{{ route('publisher.sites.create') }}">Add website</a>@endif
</section>

@if($sites->count() === 0)
    <x-empty-state title="No websites yet" description="Add your first website to begin verification and monetization setup.">
        @if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-primary" href="{{ route('publisher.sites.create') }}">Add your first website</a>@endif
    </x-empty-state>
@else
    <section class="publisher-site-grid workspace-section" aria-label="Publisher websites">
        @foreach($sites as $site)
            @php
                $next = match ($site->status) {
                    AppEnumsSiteStatus::Draft => 'Finish website setup',
                    AppEnumsSiteStatus::PendingVerification => 'Complete verification',
                    AppEnumsSiteStatus::PendingReview => 'Horus review in progress',
                    AppEnumsSiteStatus::Approved, AppEnumsSiteStatus::Active => 'Monetization active',
                    AppEnumsSiteStatus::Rejected => 'Review required changes',
                    AppEnumsSiteStatus::Suspended => 'Website paused',
                    default => 'Open website',
                };
            @endphp
            <a class="publisher-site-card" href="{{ route('publisher.sites.show', $site) }}">
                <div class="workspace-heading">
                    <div><p class="eyebrow">{{ $site->primary_domain }}</p><h2>{{ $site->display_name }}</h2></div>
                    <x-status-badge :status="$site->status" />
                </div>
                <p>{{ $next }}</p>
                <span class="section-anchor">Open website →</span>
            </a>
        @endforeach
    </section>
    {{ $sites->links() }}
@endif
@endsection
