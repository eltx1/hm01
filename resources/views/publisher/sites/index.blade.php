@extends('layouts.admin')
@section('title', 'Websites')
@section('heading', 'Websites')
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Monetization</p>
        <h2>Your websites</h2>
        <p>Open a website to see its setup, ads.txt status, monetization health and ad codes. You do not need to navigate through separate technical areas.</p>
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
    <div class="publisher-site-grid">
        @foreach($sites as $site)
            @php
                $nextStep = match ($site->status) {
                    \App\Enums\SiteStatus::Draft, \App\Enums\SiteStatus::PendingVerification => 'Finish setup and verification',
                    \App\Enums\SiteStatus::PendingReview => 'Horus review is in progress',
                    \App\Enums\SiteStatus::Approved, \App\Enums\SiteStatus::Active => 'View monetization, reports and ad codes',
                    \App\Enums\SiteStatus::Suspended => 'Review the website status and required action',
                    default => 'Open website details',
                };
            @endphp
            <article class="publisher-site-card">
                <div class="workspace-heading">
                    <div><p class="eyebrow">{{ $site->primary_domain }}</p><h2>{{ $site->display_name }}</h2></div>
                    <x-status-badge :status="$site->status" />
                </div>
                <p>{{ $nextStep }}</p>
                <div class="site-card-meta">
                    <span>Serving: {{ str($site->serving_mode->value)->replace('_', ' ')->headline() }}</span>
                </div>
                <a class="hm-button-secondary button-link" href="{{ route('publisher.sites.show', $site) }}">Open website →</a>
            </article>
        @endforeach
    </div>
    {{ $sites->links() }}
@endif
@endsection
