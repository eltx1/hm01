@extends('layouts.admin')
@section('title', 'Websites')
@section('heading', 'My websites')
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Websites</p>
        <h2>Manage each website from one place.</h2>
        <p>Open a website to see its status, verification, installation code, and setup. Use Monetization Health when you only need to know whether ads are working.</p>
        <div class="status-row">
            @if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-primary button-link" href="{{ route('publisher.sites.create') }}">Add website</a>@endif
            <a class="hm-button-secondary button-link" href="{{ route('publisher.monetization.index') }}">Monetization health</a>
            @if(auth()->user()->hasPermission('finance.publisher.view_own'))<a class="hm-button-secondary button-link" href="{{ route('publisher.finance.overview') }}">Reports &amp; earnings</a>@endif
        </div>
    </div>
</section>

@if($sites->count() === 0)
    <x-empty-state title="No websites yet" description="Add your first website to begin verification and monetization.">
        @if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-primary" href="{{ route('publisher.sites.create') }}">Add your first website</a>@endif
    </x-empty-state>
@else
    <section class="site-card-grid" aria-label="Publisher websites">
        @foreach($sites as $site)
            <article class="site-card">
                <div class="site-card-header">
                    <div>
                        <p class="eyebrow">{{ $site->primary_domain }}</p>
                        <h2>{{ $site->display_name }}</h2>
                    </div>
                    <x-status-badge :status="$site->status" />
                </div>
                <div class="summary-grid">
                    <div><strong>Status</strong><span>{{ str($site->status->value)->replace('_', ' ')->headline() }}</span></div>
                    <div><strong>Serving</strong><span>{{ str($site->serving_mode->value)->replace('_', ' ')->headline() }}</span></div>
                </div>
                <div class="site-card-actions">
                    <a class="hm-button-primary button-link" href="{{ route('publisher.sites.show', $site) }}">Open website</a>
                    @if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-secondary button-link" href="{{ route('publisher.sites.edit', $site) }}">Edit details</a>@endif
                </div>
            </article>
        @endforeach
    </section>
    {{ $sites->links() }}
@endif
@endsection
