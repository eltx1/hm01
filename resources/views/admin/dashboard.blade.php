@extends('layouts.admin')

@section('title', 'Platform Foundation')
@section('heading', 'Platform Foundation')

@section('content')
    <section class="hero">
        <div>
            <p class="eyebrow">Default serving network</p>
            <h2>HORUS_GAM</h2>
            <p>The control plane is ready for phased implementation. Serving-mode
                changes will never require publishers to replace their loader.</p>
        </div>
        <span class="pill">Default</span>
    </section>

    <section class="cards" aria-label="Foundation status">
        <article>
            <p class="eyebrow">Control plane</p>
            <h3>Laravel + MySQL</h3>
            <p>Secure sessions, cron scheduling, structured logs, and audit records.</p>
        </article>
        <article>
            <p class="eyebrow">Serving path</p>
            <h3>Browser direct</h3>
            <p>Prebid.js and GPT send ad traffic directly to the selected GAM network.</p>
        </article>
        <article>
            <p class="eyebrow">Deployment</p>
            <h3>Hostinger ready</h3>
            <p>No Redis, Supervisor, Docker, WebSockets, or permanent Node runtime.</p>
        </article>
    </section>
@endsection
