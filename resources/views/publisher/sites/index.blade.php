@extends('layouts.admin')
@section('title', 'Websites')
@section('heading', 'Websites')
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Your inventory</p>
        <h2>Choose a website to see exactly what it needs</h2>
        <p>Open a website for verification, ads.txt, monetization health and installation details. You do not need to navigate separate technical systems.</p>
    </div>
    @if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-primary" href="{{ route('publisher.sites.create') }}">Add website</a>@endif
</section>

@if($sites->count() === 0)
    <x-empty-state title="No websites yet" description="Add your first website to begin verification and monetization.">
        @if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-primary" href="{{ route('publisher.sites.create') }}">Add your first website</a>@endif
    </x-empty-state>
@else
    <article class="workspace-section">
        <div class="workspace-heading"><div><p class="eyebrow">All websites</p><h2>{{ $sites->total() }} website{{ $sites->total() === 1 ? '' : 's' }}</h2></div></div>
        <div class="compact-list">
            @foreach($sites as $site)
                <a class="compact-row" href="{{ route('publisher.sites.show', $site) }}">
                    <div>
                        <strong>{{ $site->display_name }}</strong>
                        <p>{{ $site->primary_domain }} · {{ str($site->serving_mode->value)->replace('_', ' ')->headline() }}</p>
                    </div>
                    <div class="status-row"><x-status-badge :status="$site->status" /><span class="section-anchor">Open →</span></div>
                </a>
            @endforeach
        </div>
    </article>
    {{ $sites->links() }}
@endif
@endsection
