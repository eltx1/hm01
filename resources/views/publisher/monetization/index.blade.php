@extends('layouts.admin')
@section('title', 'Monetization & Health')
@section('heading', 'Monetization & Health')
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Website health</p>
        <h2>Know what is working and what needs action</h2>
        <p>Each website gets one overall status. Open technical details only when you need them.</p>
    </div>
    @if(auth()->user()->hasPermission('sites.view'))<a class="hm-button-secondary" href="{{ route('publisher.sites.index') }}">All websites</a>@endif
</section>

@forelse($sites as $site)
    @php
        $state = $health->get($site->id);
        $actions = collect($state['modules'])->filter(fn ($module) => filled($module['action_required'] ?? null));
    @endphp
    <article class="workspace-section">
        <div class="workspace-heading">
            <div>
                <p class="eyebrow">{{ $site->primary_domain }}</p>
                <h2>{{ $site->display_name }}</h2>
                <p>{{ $state['overall']['reason'] }}</p>
            </div>
            <x-status-badge :status="$state['overall']['status']" />
        </div>

        @if($actions->isNotEmpty())
            <div class="notice">
                <strong>Next action</strong>
                <p>{{ $actions->first()['action_required'] }}</p>
            </div>
        @endif

        <div class="button-row">
            <a class="hm-button-primary button-link" href="{{ route('publisher.sites.show', $site) }}">Open website</a>
            @if(auth()->user()->hasPermission('publisher.ads_txt.view'))<a class="text-link" href="{{ route('publisher.ads-txt.index') }}">Ads.txt &amp; compliance</a>@endif
        </div>

        <details class="secondary-workflow">
            <summary>Technical health details</summary>
            <div class="health-grid">
                @foreach($state['modules'] as $module)
                    <div>
                        <span class="muted">{{ $module['title'] }}</span>
                        <x-status-badge :status="$module['status']" />
                        <small>{{ $module['reason'] }}</small>
                        @if($module['action_required'])<small><strong>Action:</strong> {{ $module['action_required'] }}</small>@endif
                    </div>
                @endforeach
            </div>
        </details>
    </article>
@empty
    <x-empty-state title="No websites yet" description="Add a website first; Horus will then show monetization health and any required action.">
        @if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-primary" href="{{ route('publisher.sites.create') }}">Add website</a>@endif
    </x-empty-state>
@endforelse

{{ $sites->links() }}
@endsection
