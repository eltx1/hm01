@extends('layouts.admin')
@section('title', 'Traffic Quality')
@section('heading', 'Traffic Quality')
@section('content')
@php
    $candidateFingerprint = $candidateSitekey ? substr(hash('sha256', $candidateSitekey), 0, 12) : null;
    $canEmergency = auth()->user()->hasPermission('traffic_gate.emergency_disable');
@endphp

<section class="hero workspace-section">
    <div>
        <p class="eyebrow">Operations · Traffic Quality</p>
        <h2>CLIENT TRAFFIC GATE</h2>
        <p>This client gate is a soft browser traffic filter. Horus does not perform server-side Turnstile token validation for ad serving.</p>
        <div class="status-row">
            <x-status-badge :status="$global['status']" />
            <x-status-badge :status="$global['readiness']" />
            <span class="muted">{{ $global['validation_mode'] }}</span>
        </div>
        <a class="hm-button-secondary button-link" href="{{ route('admin.operations.index') }}">Back to Production Operations</a>
    </div>
</section>

<section class="metric-grid" aria-label="Client Traffic Gate global status">
    <article><p class="eyebrow">Status</p><strong class="metric-small">{{ $global['status'] }}</strong><small>Readiness: {{ $global['readiness'] }}</small></article>
    <article><p class="eyebrow">Provider</p><strong class="metric-small">{{ $global['provider'] }}</strong><small>Widget: {{ $global['widget'] }}</small></article>
    <article><p class="eyebrow">Gate Origin</p><strong class="metric-small">verify.horusmedia.net</strong><small>{{ $global['origin'] }}</small></article>
    <article><p class="eyebrow">Policy</p><strong class="metric-small">{{ $global['policy'] }}</strong><small>Protection ↔ revenue resilience</small></article>
    <article><p class="eyebrow">Sites</p><strong class="metric-small">{{ $siteCounts['total'] }}</strong><small>{{ $siteCounts['enabled'] }} enabled · {{ $siteCounts['disabled'] }} disabled · {{ $siteCounts['inherit'] }} inherit</small></article>
    <article><p class="eyebrow">Static publication</p><strong class="metric-small">{{ $staticPublication['state'] }}</strong><small>{{ $staticPublication['pending'] }} pending · {{ $staticPublication['failed'] }} failed · {{ $staticPublication['deployed'] }} deployed</small></article>
</section>

<section class="detail-grid workspace-section" id="master-switch">
    <article>
        <p class="eyebrow">Master switch</p><h2>Global operation</h2>
        <p class="muted">Normal enable/disable changes publish through NORMAL Static Delivery. Global readiness must be READY before enable.</p>
        <div class="compact-row"><div><strong>{{ $global['requested_enabled'] ? 'Configured ON' : 'Configured OFF' }}</strong><p>{{ $impact['global_enable'] }} active Site(s) inherit the global switch and would be affected by an enable transition.</p></div><x-status-badge :status="$global['readiness']" /></div>
        <form method="POST" action="{{ route('admin.operations.traffic-quality.master') }}" class="form-stack">
            @csrf
            <input type="hidden" name="enabled" value="{{ $global['requested_enabled'] ? 0 : 1 }}">
            <label>Required reason<input class="hm-input" name="reason" minlength="8" maxlength="2000" required></label>
            <label>Current password<input class="hm-input" type="password" name="current_password" autocomplete="current-password" required></label>
            <label>Type <code>{{ $global['requested_enabled'] ? 'DISABLE CLIENT TRAFFIC GATE' : 'ENABLE CLIENT TRAFFIC GATE' }}</code>
                <input class="hm-input" name="impact_confirmation" required>
            </label>
            <button class="{{ $global['requested_enabled'] ? 'hm-button-danger' : 'hm-button-primary' }}" @disabled(! $global['requested_enabled'] && $global['readiness'] !== 'READY')>
                {{ $global['requested_enabled'] ? 'DISABLE CLIENT TRAFFIC GATE' : 'ENABLE CLIENT TRAFFIC GATE' }}
            </button>
        </form>
    </article>

    <article class="danger-zone">
        <p class="eyebrow">Incident control</p><h2>Emergency Disable Traffic Gate</h2>
        <p class="muted">Emergency disable is authoritative over Site overrides and queues an URGENT static publication. Clearing the emergency returns to the configured global/Site state through NORMAL delivery.</p>
        <x-status-badge :status="$global['emergency_disabled'] ? 'EMERGENCY DISABLED' : 'CLEAR'" />
        @if($canEmergency)
            <form method="POST" action="{{ $global['emergency_disabled'] ? route('admin.operations.traffic-quality.clear-emergency') : route('admin.operations.traffic-quality.emergency-disable') }}" class="form-stack">
                @csrf
                <label>Required incident reason<input class="hm-input" name="reason" minlength="8" maxlength="2000" required></label>
                <label>Current password<input class="hm-input" type="password" name="current_password" autocomplete="current-password" required></label>
                <label>Type <code>{{ $global['emergency_disabled'] ? 'CLEAR TRAFFIC GATE EMERGENCY' : 'EMERGENCY DISABLE TRAFFIC GATE' }}</code><input class="hm-input" name="impact_confirmation" required></label>
                <button class="{{ $global['emergency_disabled'] ? 'hm-button-secondary' : 'hm-button-danger' }}">{{ $global['emergency_disabled'] ? 'Clear emergency disable' : 'Emergency Disable Traffic Gate' }}</button>
            </form>
        @else<p class="muted">Your role cannot use the emergency control.</p>@endif
    </article>
</section>

<article class="workspace-section" id="policy">
    <div class="workspace-heading"><div><p class="eyebrow">Policy presets</p><h2>Choose operating posture</h2><p class="muted">{{ $impact['global_policy'] }} effective active Site(s) currently inherit the global policy.</p></div></div>
    <div class="health-grid">
        @foreach([
            'STRICT' => ['Only a client Turnstile PASS permits monetization.', 'Protection: highest', 'Revenue resilience: lowest'],
            'BALANCED' => ['PASS starts ads immediately. Technical failure waits for trusted visitor activity before soft-allowing.', 'Protection: balanced', 'Revenue resilience: balanced'],
            'PERMISSIVE' => ['PASS starts ads immediately. Technical failure may soft-allow after the configured timeout.', 'Protection: lower', 'Revenue resilience: highest'],
        ] as $policy => $copy)
            <div>
                <span class="muted">{{ $policy === 'BALANCED' ? 'Recommended' : 'Preset' }}</span>
                <strong class="metric-small">{{ $policy }}</strong>
                <p>{{ $copy[0] }}</p><small>{{ $copy[1] }} · {{ $copy[2] }}</small>
                @if($policy !== $global['policy'])
                    <form method="POST" action="{{ route('admin.operations.traffic-quality.policy') }}" class="form-stack" style="margin-top:.75rem">
                        @csrf<input type="hidden" name="policy" value="{{ $policy }}">
                        <label>Required reason<input class="hm-input" name="reason" minlength="8" required></label>
                        <label>Current password<input class="hm-input" type="password" name="current_password" autocomplete="current-password" required></label>
                        @if($policy === 'STRICT')<p class="notice error">STRICT can suppress monetization during technical Turnstile failure because it never technically soft-allows.</p>@endif
                        <label>Type <code>{{ $policy === 'STRICT' ? 'SET STRICT TRAFFIC GATE' : 'CHANGE TRAFFIC GATE POLICY' }}</code><input class="hm-input" name="impact_confirmation" required></label>
                        <button class="hm-button-secondary">Set {{ $policy }}</button>
                    </form>
                @else<x-status-badge status="ACTIVE" />@endif
            </div>
        @endforeach
    </div>
</article>

<details class="workspace-section" id="advanced">
    <summary><strong>Advanced settings</strong> · bounded timing and trusted activity recovery</summary>
    <form method="POST" action="{{ route('admin.operations.traffic-quality.advanced') }}" class="form-stack" style="margin-top:1rem">
        @csrf
        <div class="detail-grid">
            <label>Initial wait <small>500–5000 milliseconds ({{ number_format($global['initial_wait_ms'] / 1000, 2) }} seconds)</small><input class="hm-input" type="number" min="500" max="5000" step="100" name="initial_wait_ms" value="{{ $global['initial_wait_ms'] }}" required></label>
            <label>Maximum wait <small>2000–15000 milliseconds ({{ number_format($global['max_wait_ms'] / 1000, 2) }} seconds)</small><input class="hm-input" type="number" min="2000" max="15000" step="100" name="max_wait_ms" value="{{ $global['max_wait_ms'] }}" required></label>
            <label>Retry interval <small>500–10000 milliseconds ({{ number_format($global['retry_interval_ms'] / 1000, 2) }} seconds)</small><input class="hm-input" type="number" min="500" max="10000" step="100" name="retry_interval_ms" value="{{ $global['retry_interval_ms'] }}" required></label>
            <label>Trusted activity recovery<select class="hm-input" name="activity_recovery_enabled"><option value="1" @selected($global['activity_recovery_enabled'])>Enabled</option><option value="0" @selected(! $global['activity_recovery_enabled'])>Disabled</option></select></label>
        </div>
        <label>Required reason<input class="hm-input" name="reason" minlength="8" required></label>
        <label>Current password<input class="hm-input" type="password" name="current_password" autocomplete="current-password" required></label>
        <label>Type <code>UPDATE TRAFFIC GATE TIMINGS</code><input class="hm-input" name="impact_confirmation" required></label>
        <button class="hm-button-primary">Save advanced settings</button>
    </form>
</details>

<section class="detail-grid workspace-section" id="sitekey-management">
    <article>
        <p class="eyebrow">Public Sitekey</p><h2>Sitekey management</h2>
        <div class="compact-row"><div><strong>Current Sitekey: {{ $global['sitekey_configured'] ? 'configured' : 'not configured' }}</strong><p>Replacement is staged until a browser Client Test passes and an Admin explicitly activates it.</p></div><x-status-badge :status="$global['sitekey_configured'] ? 'CONFIGURED' : 'NOT CONFIGURED'" /></div>
        <form method="POST" action="{{ route('admin.operations.traffic-quality.sitekey.candidate') }}" class="form-stack">
            @csrf
            <label>Candidate public Sitekey<input class="hm-input" name="candidate_sitekey" autocomplete="off" required></label>
            <button class="hm-button-secondary">Replace Sitekey · Stage Candidate</button>
        </form>
    </article>
    <article>
        <p class="eyebrow">Task 49 Admin-test protocol</p><h2>Client Test</h2>
        @if($candidateSitekey)
            <p>Candidate staged · fingerprint <code>{{ $candidateFingerprint }}</code></p>
            <div class="compact-row"><span>Result</span><x-status-badge :status="$candidateTestResult ?: 'NOT TESTED'" /></div>
            <button id="traffic-gate-client-test" class="hm-button-secondary" type="button" data-candidate="{{ $candidateSitekey }}" data-origin="{{ $global['origin'] }}">Test Candidate</button>
            <p id="traffic-gate-client-test-status" class="muted" aria-live="polite">{{ $candidateTestResult ?: 'No Client Test result yet.' }}</p>
            <form method="POST" action="{{ route('admin.operations.traffic-quality.sitekey.activate') }}" class="form-stack">
                @csrf
                <label>Activation reason<input class="hm-input" name="reason" minlength="8" required></label>
                <label>Current password<input class="hm-input" type="password" name="current_password" autocomplete="current-password" required></label>
                <label>Type <code>ACTIVATE TRAFFIC GATE SITEKEY</code><input class="hm-input" name="impact_confirmation" required></label>
                <button id="traffic-gate-activate" class="hm-button-primary" @disabled($candidateTestResult !== 'CLIENT PASS')>Activate Candidate</button>
            </form>
            @if($candidateTestResult && $candidateTestResult !== 'CLIENT PASS')<p class="notice error">Normal activation is blocked because the Client Test did not return CLIENT PASS.</p>@endif
        @else<p class="muted">Stage a candidate Sitekey to run a Client Test.</p>@endif
    </article>
</section>

<article class="workspace-section" id="sites">
    <div class="workspace-heading"><div><p class="eyebrow">Per-Site control</p><h2>Site overrides</h2><p class="muted">Global timings remain global. Site controls only change gate state and policy override.</p></div></div>
    <form method="GET" action="{{ route('admin.operations.traffic-quality') }}" class="inline-form"><input class="hm-input" name="q" value="{{ $search }}" placeholder="Search Site, publisher, or domain"><button class="hm-button-secondary">Search</button></form>
    <form id="bulk-inherit-form" method="POST" action="{{ route('admin.operations.traffic-quality.sites.bulk-inherit') }}" class="form-stack" style="margin:1rem 0">
        @csrf
        <div class="inline-form"><input class="hm-input" name="reason" minlength="8" placeholder="Bulk reset reason" required><input class="hm-input" type="password" name="current_password" placeholder="Current password" required><input class="hm-input" name="impact_confirmation" placeholder="RESET SELECTED SITES TO INHERIT" required><button class="hm-button-secondary">Reset selected Sites to INHERIT</button></div>
    </form>
    <div class="table-scroll" data-mobile-responsive-table>
        <table class="hm-table">
            <thead><tr><th>Select</th><th>Site</th><th>Publisher</th><th>Domain</th><th>Effective Gate</th><th>Override</th><th>Policy</th><th>Static status</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($sites as $row)
                <tr>
                    <td><input type="checkbox" name="site_ids[]" value="{{ $row['site']->id }}" form="bulk-inherit-form" aria-label="Select {{ $row['site']->display_name }}"></td>
                    <td><a href="{{ route('admin.sites.show', $row['site']) }}"><strong>{{ $row['site']->display_name }}</strong></a></td>
                    <td>{{ $row['site']->publisher?->display_name ?: '—' }}</td><td>{{ $row['site']->primary_domain }}</td>
                    <td><x-status-badge :status="$row['effective_gate']" /></td><td>{{ $row['override'] }}</td><td>{{ $row['policy'] }} <small>{{ $row['policy_override'] === 'INHERIT' ? 'inherited' : 'override' }}</small></td><td><x-status-badge :status="$row['static_status']" /></td>
                    <td>
                        <form method="POST" action="{{ route('admin.sites.traffic-gate', $row['site']) }}" class="form-stack">
                            @csrf
                            <select class="hm-input" name="traffic_gate_state" aria-label="Gate override for {{ $row['site']->display_name }}">@foreach(\App\Enums\TrafficGateSiteState::cases() as $state)<option value="{{ $state->value }}" @selected($row['override'] === $state->value)>{{ $state->value }}</option>@endforeach</select>
                            <select class="hm-input" name="traffic_gate_policy" aria-label="Gate policy for {{ $row['site']->display_name }}">@foreach(\App\Enums\TrafficGateSitePolicy::cases() as $policy)<option value="{{ $policy->value }}" @selected($row['policy_override'] === $policy->value)>{{ $policy->value }}</option>@endforeach</select>
                            <input class="hm-input" name="reason" minlength="8" placeholder="Required reason" required><button class="hm-button-secondary">Save</button>
                        </form>
                    </td>
                </tr>
            @empty<tr><td colspan="9" class="muted">No Sites match this search.</td></tr>@endforelse
            </tbody>
        </table>
    </div>
    {{ $sites->links() }}
</article>

<section class="detail-grid workspace-section">
    <article><p class="eyebrow">Analytics boundary</p><h2>Challenge analytics</h2><p class="muted">Horus does not create pass counters, error beacons, visitor event tables, or browser fingerprints for this feature. The Cloudflare dashboard is the external source for Turnstile widget challenge analytics.</p></article>
    <article><p class="eyebrow">Recent audit</p><h2>Traffic Gate changes</h2>@forelse($recentAudit as $event)<div class="event"><strong>{{ $event->event }}</strong><span>{{ $event->created_at }} · {{ $event->actor_id ?: 'system' }}</span></div>@empty<p class="muted">No Traffic Gate audit events available.</p>@endforelse</article>
</section>

@if($candidateSitekey)
<script>
(() => {
    const button = document.getElementById('traffic-gate-client-test');
    if (!button) return;
    const status = document.getElementById('traffic-gate-client-test-status');
    const activate = document.getElementById('traffic-gate-activate');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const resultUrl = @json(route('admin.operations.traffic-quality.sitekey.test-result'));
    const protocolVersion = 1;
    let frame = null;
    let watchdog = null;
    let nonce = null;

    function makeNonce() {
        const bytes = new Uint8Array(24);
        crypto.getRandomValues(bytes);
        return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
    }
    async function record(result) {
        status.textContent = result;
        if (activate) activate.disabled = result !== 'CLIENT PASS';
        await fetch(resultUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
            body: JSON.stringify({result}),
        });
    }
    function finish(result) {
        if (watchdog) clearTimeout(watchdog);
        watchdog = null;
        window.removeEventListener('message', onMessage, false);
        if (frame?.parentNode) frame.parentNode.removeChild(frame);
        frame = null;
        record(result).catch(() => { status.textContent = result + ' · result audit could not be recorded'; });
    }
    function onMessage(event) {
        if (!frame || event.origin !== button.dataset.origin || event.source !== frame.contentWindow) return;
        const message = event.data;
        if (!message || typeof message !== 'object' || message.protocolVersion !== protocolVersion || message.pageNonce !== nonce) return;
        if (message.type === 'HORUS_TRAFFIC_GATE_PASS') finish('CLIENT PASS');
        else if (message.type === 'HORUS_TRAFFIC_GATE_TIMEOUT') finish('CLIENT TIMEOUT');
        else if (message.type === 'HORUS_TRAFFIC_GATE_ERROR' || message.type === 'HORUS_TRAFFIC_GATE_DENIED') finish('CLIENT ERROR');
    }
    button.addEventListener('click', () => {
        if (!window.crypto?.getRandomValues) { record('GATE UNREACHABLE'); return; }
        button.disabled = true;
        status.textContent = 'Running client-only test…';
        nonce = makeNonce();
        frame = document.createElement('iframe');
        frame.src = button.dataset.origin + '/traffic-gate/';
        frame.title = 'Horus Traffic Gate Client Test';
        frame.setAttribute('aria-hidden', 'true');
        frame.style.cssText = 'position:fixed;width:1px;height:1px;left:-10000px;top:-10000px;border:0;opacity:0;pointer-events:none';
        window.addEventListener('message', onMessage, false);
        frame.onerror = () => finish('GATE UNREACHABLE');
        frame.onload = () => frame.contentWindow?.postMessage({
            type: 'HORUS_TRAFFIC_GATE_HELLO', protocolVersion, pageNonce: nonce,
            sitePublicKey: 'admin-test', testMode: true, candidateSiteKey: button.dataset.candidate,
        }, button.dataset.origin);
        document.body.appendChild(frame);
        watchdog = setTimeout(() => finish('GATE UNREACHABLE'), 8000);
        setTimeout(() => { if (button) button.disabled = false; }, 8500);
    });
})();
</script>
@endif
@endsection
