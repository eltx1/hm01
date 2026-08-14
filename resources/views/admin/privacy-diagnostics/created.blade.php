@extends('layouts.admin')
@section('title', 'Live Privacy Test · '.$site->display_name)
@section('heading', 'Live Privacy Test · '.$site->display_name)
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Explicit one-shot diagnostic</p>
        <h2>Diagnostic link created</h2>
        <p>Open the publisher website using the link below before <strong>{{ $diagnostic['expires_at'] }}</strong>. The token is single-use and is stored by Horus only as a one-way hash.</p>
    </div>
    <x-status-badge status="READY" />
</section>
<article class="workspace-section">
    <h3>Open the authorized publisher website</h3>
    <p><a class="hm-button-primary button-link" href="{{ $diagnostic['url'] }}" target="_blank" rel="noopener noreferrer">Run Live Privacy Test</a></p>
    <code class="installation-code">{{ $diagnostic['url'] }}</code>
    <p class="muted">Only this explicit page load may send one sanitized privacy diagnostic to the control plane. Normal Loader boot sends no diagnostic request.</p>
    <p><a class="hm-button-secondary button-link" href="{{ route('admin.sites.show', $site) }}#privacy-readiness">Return to Privacy Readiness</a></p>
</article>
@endsection
