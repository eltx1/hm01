@extends('layouts.admin')
@section('title', 'Monetization')
@section('heading', 'Monetization')
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Publisher workspace</p>
        <h2>Is monetization working?</h2>
        <p>Each website shows one overall answer first. Open a site only when you need the detailed setup or troubleshooting steps.</p>
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
                <a class="hm-button-secondary button-link" href="{{ route('publisher.sites.show', $site) }}">Open website</a>
            </div>
        </div>
        <div class="health-grid">
            @foreach($state['modules'] as $module)
                <div>
                    <span class="muted">{{ $module['title'] }}</span>
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
