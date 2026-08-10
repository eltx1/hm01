@extends('layouts.admin')
@section('title', 'Monetization Center')
@section('heading', 'Monetization Center')
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Publisher workspace</p>
        <h2>Website monetization health</h2>
        <p>See whether monetization is working for each website and exactly what action is required when it is not.</p>
        <p class="muted">Optional products never make an otherwise healthy website look broken. Health is based on persisted Horus state; this page does not call Google or demand/reporting providers while rendering.</p>
    </div>
</section>

@forelse($sites as $site)
    @php($state = $health->get($site->id))
    <article class="workspace-section">
        <div class="workspace-heading">
            <div>
                <p class="eyebrow">{{ $site->primary_domain }}</p>
                <h2>{{ $site->display_name }}</h2>
                <p>{{ $state['overall']['reason'] }}</p>
            </div>
            <div>
                <x-status-badge :status="$state['overall']['status']" />
                <a class="hm-button-secondary button-link" href="{{ route('publisher.sites.show', $site) }}">Open site health</a>
            </div>
        </div>
        <div class="health-grid">
            @foreach($state['modules'] as $module)
                <div>
                    <span class="muted">{{ $module['title'] }} · {{ $module['dependency'] }}</span>
                    <x-status-badge :status="$module['status']" />
                    <small>{{ $module['reason'] }}</small>
                    @if($module['action_required'])
                        <small><strong>Next action:</strong> {{ $module['action_required'] }}</small>
                    @endif
                </div>
            @endforeach
        </div>
    </article>
@empty
    <section><p>No websites are available yet. Add a website to begin monetization readiness.</p></section>
@endforelse

{{ $sites->links() }}
@endsection
