@extends('layouts.admin')
@section('title', 'Direct Demand Tag Review')
@section('heading', 'Direct Demand · Tag Review')
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Safe paste-and-parse review</p>
        <h2>{{ $account->name }}</h2>
        <p>This preview parses provider markup as text. It never executes the pasted tag in the Horus Admin top window.</p>
    </div>
    <div class="status-row">
        <span class="pill">{{ ($review['safe'] ?? false) ? 'STRUCTURED SAFE' : 'REVIEW REQUIRED' }}</span>
        <a class="hm-button-secondary" href="{{ route('admin.demand.index') }}">Back to Direct Demand</a>
    </div>
</section>

<section class="detail-grid" style="margin-top:1rem">
<article>
    <p class="eyebrow">Detected scripts</p>
    <h3>External resources</h3>
    @forelse($review['detectedScripts'] ?? [] as $script)
        <div class="event"><div><strong>{{ $script['url'] ?? 'Unknown URL' }}</strong><br><span>async={{ !empty($script['async']) ? 'yes' : 'no' }} · defer={{ !empty($script['defer']) ? 'yes' : 'no' }}</span></div></div>
    @empty<p class="muted">No external script detected.</p>@endforelse
</article>
<article>
    <p class="eyebrow">Detected container</p>
    <h3>Public render surface</h3>
    <pre>{{ json_encode($review['detectedContainers'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    <p class="muted">Public identifiers: {{ implode(', ', $review['detectedPublicIdentifiers'] ?? []) ?: 'none detected' }}</p>
</article>
</section>

<article>
    <p class="eyebrow">Policy warnings</p>
    <h3>Unsupported or unsafe material</h3>
    @forelse($review['securityWarnings'] ?? [] as $warning)<p class="error">{{ $warning }}</p>@empty<p class="muted">No security warning was produced by the structured parser.</p>@endforelse
    @if(!empty($review['unsupportedInlineCode']))
        <p class="muted">Inline JavaScript is displayed only as escaped text and is never executed here.</p>
        <pre>{{ implode("\n---\n", $review['unsupportedInlineCode']) }}</pre>
    @endif
</article>

<article>
    <p class="eyebrow">Normalized recipe</p>
    <h3>Reviewed public runtime data</h3>
    @if($review['recipe'] ?? null)
        <pre>{{ json_encode($review['recipe'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    @else
        <p class="error">No structured production recipe was generated. Use an approved isolated Custom Third Party flow only when the provider cannot be represented structurally.</p>
    @endif
</article>
@endsection
