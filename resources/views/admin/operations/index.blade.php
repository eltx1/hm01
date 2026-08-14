@extends('layouts.admin')
@section('title', 'Operations Center')
@section('heading', 'Operations Control Center')
@section('content')
<section class="metric-grid">
    <article><p class="eyebrow">Scheduler heartbeat</p><strong class="metric">{{ $heartbeatStale ? 'STALE' : 'HEALTHY' }}</strong><p class="muted">{{ $heartbeat?->last_seen_at ?: 'Never recorded' }}</p></article>
    <article><p class="eyebrow">Static delivery failures</p><strong class="metric">{{ $overview['failed_static_deliveries'] }}</strong><p class="muted">{{ $overview['retry_scheduled_deliveries'] }} retry scheduled · {{ $overview['retry_exhausted'] }} exhausted</p></article>
    <article><p class="eyebrow">GAM health issues</p><strong class="metric">{{ $overview['gam_unhealthy'] }}</strong><p class="muted">Enabled connections that are degraded, failed, or not yet verified.</p></article>
    <article><p class="eyebrow">Demand health issues</p><strong class="metric">{{ $overview['demand_unhealthy'] }}</strong><p class="muted">Enabled accounts lacking approval or a successful persisted sync.</p></article>
</section>

<section>
    <h2>Global edge engine state</h2>
    <p class="muted">The edge artifact enforces these controls before any engine initializes. All Ad Serving is the master switch; engine switches remain independent when the master is ON.</p>
    <div class="metric-grid">
        @foreach($globalEngineControls as $engineControl)
            <article>
                <p class="eyebrow">{{ $engineControl['label'] }}</p>
                <strong class="metric">{{ $engineControl['disabled'] ? 'OFF · DISABLED' : 'ON' }}</strong>
                <p class="muted">
                    @if($engineControl['reason'])
                        {{ $engineControl['reason'] }}<br>
                        {{ $engineControl['actor'] ?: 'system' }} · {{ $engineControl['changed_at'] }}
                    @else
                        No disabling override recorded.
                    @endif
                </p>
            </article>
        @endforeach
    </div>
</section>
<section class="metric-grid">
    <article><p class="eyebrow">Failed report imports</p><strong class="metric">{{ $overview['failed_report_imports'] }}</strong></article>
    <article><p class="eyebrow">Paused / disabled sites</p><strong class="metric">{{ $overview['paused_or_disabled_sites'] }}</strong></article>
    <article><p class="eyebrow">Disabled placements</p><strong class="metric">{{ $overview['disabled_placements'] }}</strong></article>
    <article><p class="eyebrow">Stale configuration</p><strong class="metric">{{ $overview['stale_configuration'] }}</strong><p class="muted">Draft configuration is newer than production.</p></article>
    <article><p class="eyebrow">Active operational controls</p><strong class="metric">{{ $overview['active_controls'] }}</strong></article>
</section>
<section class="metric-grid">
    <article><p class="eyebrow">Pilot go/no-go</p><strong class="metric">{{ $pilotReady ? 'GO' : 'NO-GO' }}</strong><p class="muted">Requires fresh passing CDN probes, scheduler heartbeat, and no failed static delivery.</p></article>
    <article><p class="eyebrow">Synthetic coverage</p><strong class="metric">{{ $latestProbes->where('status', 'PASS')->count() }}/{{ $latestProbes->count() }}</strong><p class="muted">Persisted probes only. No per-impression telemetry is ingested here.</p></article>
    <article><p class="eyebrow">Failed queue jobs</p><strong class="metric">{{ $overview['failed_queue_jobs'] }}</strong><p class="muted">Generic retries are intentionally disabled; use the owning idempotent domain workflow.</p></article>
</section>

<section>
    <h2>Latest significant operational events</h2>
    <div class="table-wrap"><table><thead><tr><th>Time</th><th>Event</th><th>Entity</th><th>Route</th></tr></thead><tbody>
    @forelse($overview['latest_events'] as $event)
        <tr><td>{{ $event->created_at }}</td><td>{{ $event->event }}</td><td><code>{{ $event->auditable_type ?: 'platform' }}{{ $event->auditable_id ? ' · '.$event->auditable_id : '' }}</code></td><td>{{ data_get($event->metadata, 'route') ?: '—' }}</td></tr>
    @empty<tr><td colspan="4">No significant operational audit events yet.</td></tr>@endforelse
    </tbody></table></div>
    @can('audit.view')<p><a href="{{ route('admin.audit.index') }}">Open full Audit Explorer →</a></p>@endcan
</section>

<section>
    <h2>Static runtime probes</h2>
    <div class="table-wrap"><table><thead><tr><th>Site</th><th>Status</th><th>Latency</th><th>Observed</th></tr></thead><tbody>
    @forelse($latestProbes as $probe)<tr><td>{{ $probe->site_id }}</td><td>{{ $probe->status }}</td><td>{{ $probe->latency_ms }} ms</td><td>{{ $probe->observed_at }}</td></tr>
    @empty<tr><td colspan="4">Run <code>php artisan adtech:probe</code> before pilot approval.</td></tr>@endforelse
    </tbody></table></div>
</section>

<section>
    <h2>Static Delivery</h2>
    <p class="muted">NORMAL changes publish at the next deterministic UTC half-hour boundary. URGENT safety work is immediately eligible. Deploy Now only accelerates currently pending NORMAL work through the same validated, budgeted pipeline.</p>
    @if($staticDelivery['warnings'])
        @foreach($staticDelivery['warnings'] as $warning)
            <p><strong>{{ $warning['code'] }}</strong> · {{ $warning['message'] }}</p>
        @endforeach
    @endif
    <h3>Current state</h3>
    <div class="metric-grid">
        <article><p class="eyebrow">Current delivery status</p><strong class="metric">{{ $staticDelivery['current']['status'] }}</strong><p class="muted">{{ $urgentDeliveries }} unresolved urgent item(s)</p></article>
        <article><p class="eyebrow">Pending changes</p><strong class="metric">{{ $staticDelivery['current']['pending_count'] }}</strong><p class="muted">Oldest: {{ $staticDelivery['current']['oldest_pending_at'] ?: 'None' }}</p></article>
        <article><p class="eyebrow">Next normal batch · UTC</p><strong class="metric">{{ $staticDelivery['current']['next_normal_batch_at'] }}</strong><p class="muted">Scheduler checks every minute; eligibility remains boundary-based.</p></article>
        <article><p class="eyebrow">Current manifest</p><strong class="metric">{{ $staticDelivery['current']['manifest_hash'] ? substr($staticDelivery['current']['manifest_hash'], 0, 12) : 'NONE' }}</strong><p class="muted"><code>{{ $staticDelivery['current']['manifest_hash'] ?: 'No confirmed manifest' }}</code></p></article>
        <article><p class="eyebrow">Last successful deployment</p><strong class="metric">{{ $staticDelivery['current']['last_successful']?->deployed_at ?: 'NONE' }}</strong><p class="muted">{{ $staticDelivery['current']['last_successful']?->is_deduplicated ? 'Manifest deduplicated without remote quota use' : 'Confirmed delivery evidence' }}</p></article>
        <article><p class="eyebrow">Last remote deployment</p><strong class="metric">{{ $staticDelivery['current']['last_remote']?->remote_deployment_id ?: 'NONE' }}</strong><p class="muted">{{ $staticDelivery['current']['last_remote']?->deployed_at ?: 'No confirmed remote deployment' }}</p></article>
    </div>

    <h3>Monthly deployment budget</h3>
    <div class="metric-grid">
        <article><p class="eyebrow">Used remote deployments</p><strong class="metric">{{ $staticDelivery['budget']['used'] }}</strong><p class="muted">Manifest-only deduplication is excluded.</p></article>
        <article><p class="eyebrow">Normal budget</p><strong class="metric">{{ $staticDelivery['budget']['normal_budget'] }}</strong><p class="muted">{{ $staticDelivery['budget']['normal_remaining'] }} remaining · {{ $staticDelivery['budget']['state'] }}</p></article>
        <article><p class="eyebrow">Emergency reserve</p><strong class="metric">{{ $staticDelivery['budget']['emergency_remaining'] }}/{{ $staticDelivery['budget']['emergency_reserve'] }}</strong><p class="muted">{{ $staticDelivery['budget']['emergency_consumed'] }} consumed by urgent capacity.</p></article>
        <article><p class="eyebrow">Total configured budget</p><strong class="metric">{{ $staticDelivery['budget']['total'] }}</strong><p class="muted">Straight-line month projection from persisted submissions: {{ $staticDelivery['budget']['projected'] }}</p></article>
    </div>

    <h3>Current snapshot evidence</h3>
    <div class="metric-grid">
        <article><p class="eyebrow">Files</p><strong class="metric">{{ $staticDelivery['snapshot']['file_count'] }}</strong><p class="muted">Warning {{ $staticDelivery['snapshot']['warning_threshold'] }} · hard limit {{ $staticDelivery['snapshot']['hard_limit'] }} · {{ $staticDelivery['snapshot']['near_limit'] ? 'NEAR LIMIT' : 'WITHIN LIMIT' }}</p></article>
        <article><p class="eyebrow">Approximate persisted size</p><strong class="metric">{{ number_format($staticDelivery['snapshot']['total_bytes'] / 1024, 1) }} KiB</strong><p class="muted">From the latest confirmed batch; no invented infrastructure metrics.</p></article>
    </div>

    @if(auth()->user()->hasPermission('operations.manage'))
    <article>
        <h3>Deploy Pending Changes Now</h3>
        <p class="muted">Accelerates pending NORMAL work only. It does not force an identical redeploy, use emergency reserve as normal capacity, or bypass validation, checksums, secret guards, locking, or budget enforcement.</p>
        <form method="POST" action="{{ route('admin.operations.static-delivery.deploy-now') }}" class="safe-submit">@csrf
            <label>Operational reason<textarea name="reason" required minlength="8" maxlength="1000" placeholder="Why pending normal changes must publish before their scheduled boundary"></textarea></label>
            <label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
            <label><input type="checkbox" name="confirm_deploy" value="1" required> I confirm this will process currently pending NORMAL static changes through the existing delivery pipeline.</label>
            <button type="submit">Deploy Pending Changes Now</button>
        </form>
    </article>
    @endif

    <h3>Recent deployments</h3>
    <p class="muted">A batch is marked deployed only after confirmation. Identical manifests retain deduplication evidence without a redundant GitHub commit or Cloudflare deployment.</p>
    <div class="table-wrap"><table><thead><tr><th>Batch / status</th><th>Priority / trigger</th><th>Snapshot</th><th>Remote evidence</th><th>Actor / timestamps</th><th>Error / action</th></tr></thead><tbody>
    @forelse($staticDelivery['recent'] as $batch)
        <tr>
            <td><code>{{ $batch->id }}</code><br>{{ $batch->status->value }} @if($batch->is_deduplicated) · DEDUPLICATED @endif<br><span class="muted">{{ $batch->attempts }} attempt(s)</span></td>
            <td>{{ $batch->priority->value }}<br><span class="muted">{{ $batch->trigger }}</span></td>
            <td>{{ $batch->item_count }} item(s) · {{ $batch->file_count }} file(s)<br><code>{{ $batch->manifest_hash ?: 'manifest pending' }}</code></td>
            <td>{{ $batch->remote_deployment_id ?: 'No remote ID' }}<br><span class="muted">{{ $batch->is_deduplicated ? 'Reused confirmed manifest evidence' : ($batch->submitted_at ? 'Remote submission recorded' : 'No remote submission') }}</span></td>
            <td>{{ $batch->creator?->name ?: 'system' }}<br><span class="muted">created {{ $batch->created_at }}<br>deployed {{ $batch->deployed_at ?: '—' }}</span></td>
            <td>{{ $batch->error_code ?: '—' }}<br><span class="muted">{{ $batch->safe_error ?: 'No error' }} @if($batch->next_retry_at) · next {{ $batch->next_retry_at }} @endif</span><br>@if(in_array($batch->status->value, ['FAILED', 'RETRY_SCHEDULED'], true))
                <form class="inline safe-submit" method="POST" action="{{ route('admin.operations.static-delivery.retry', $batch) }}">@csrf
                    <input name="reason" required minlength="8" placeholder="Retry reason">
                    <input type="password" name="current_password" required placeholder="Password" autocomplete="current-password">
                    <button type="submit">Retry safely</button>
                </form>
            @else<span class="muted">No action</span>@endif</td>
        </tr>
    @empty<tr><td colspan="6">No static delivery batches yet.</td></tr>@endforelse
    </tbody></table></div>
</section>

<section class="split-grid">
    <article>
        <h2>Platform Control Workbench</h2>
        <p class="muted">Emergency/runtime controls only. They do not change onboarding eligibility or the HORUS_GAM default. Every actual transition requires a reason and current password, is audited, invalidates the control cache, and republishes only affected static configuration.</p>
        <form method="POST" action="{{ route('admin.operations.controls') }}" id="control-form" class="safe-submit">@csrf
            <label>Scope<select name="scope_type" id="control-scope" required>@foreach(array_keys($allowedControls) as $scope)<option value="{{ $scope }}">{{ $scope }}</option>@endforeach</select></label>
            <label>Target<select id="control-target"><option value="">Platform-wide / no target</option></select></label>
            <input type="hidden" name="scope_id" id="control-scope-id">
            <label>Control<select name="control_key" id="control-key" required></select></label>
            <label>Requested state<select name="is_disabled" id="control-state"><option value="1">Disabled / emergency pause</option><option value="0">Enabled / resume</option></select></label>
            <div id="platform-impact" hidden>
                <p><strong>High impact:</strong> a platform-wide engine disable is published urgently to the static edge and remains active until explicitly re-enabled.</p>
                <label>Enhanced confirmation<input name="impact_confirmation" id="impact-confirmation" placeholder="DISABLE PLATFORM AD SERVING" autocomplete="off"></label>
            </div>
            <label>Reason<textarea name="reason" required minlength="8" maxlength="2000" placeholder="Why this operational transition is required"></textarea></label>
            <label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
            <button type="submit">Apply audited control</button>
        </form>
    </article>
    <article>
        <h2>Current controls</h2>
        <div class="table-wrap"><table><thead><tr><th>Scope / target</th><th>Control</th><th>State</th><th>Reason / actor / time</th></tr></thead><tbody>
        @forelse($controls as $control)
            <tr><td>{{ $control->scope_type }}<br><code>{{ $control->scope_id }}</code></td><td>{{ $control->control_key }}</td><td>{{ $control->is_disabled ? 'DISABLED' : 'ENABLED' }}</td><td>{{ $control->reason }}<br><span class="muted">{{ $control->actor?->name ?? 'system' }} · {{ $control->changed_at }}</span></td></tr>
        @empty<tr><td colspan="4">No operational overrides recorded.</td></tr>@endforelse
        </tbody></table></div>
        <h2>Loader rollback</h2>
        <form method="POST" action="{{ route('admin.operations.loader.rollback') }}" class="safe-submit">@csrf
            <label>Release<select name="loader_release_id" required>@foreach($loaderReleases as $release)<option value="{{ $release->id }}">{{ $release->version }} {{ $release->is_active ? '(active)' : '' }}</option>@endforeach</select></label>
            <label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
            <button type="submit">Activate release</button>
        </form>
    </article>
</section>

<section>
    <h2>Failed queue jobs — diagnostic only</h2>
    <p class="muted">A generic queue payload may represent a non-idempotent external write. Operations therefore does not expose <code>queue:retry</code>. Retry through the owning domain workflow only when that workflow proves replay safety.</p>
    <div class="table-wrap"><table><thead><tr><th>Entity</th><th>Failure</th><th>Latest attempt</th><th>Retryability / resolution</th><th>Action</th></tr></thead><tbody>
    @forelse($failedJobs as $job)
        <tr><td><code>{{ $job->uuid }}</code><br><span class="muted">{{ $job->connection }} / {{ $job->queue }}</span></td><td>{{ $job->safe_exception ?: 'Failure details unavailable' }}</td><td>{{ $job->failed_at }}</td><td>Not proven retry-safe here<br><span class="muted">Resolve or retry from owning subsystem.</span></td><td>
            <form class="inline safe-submit" method="POST" action="{{ route('admin.operations.jobs.forget', $job->uuid) }}">@csrf @method('DELETE')
                <input name="reason" required minlength="8" placeholder="Resolution reason">
                <input type="password" name="current_password" required placeholder="Password" autocomplete="current-password">
                <button type="submit">Forget resolved failure</button>
            </form>
        </td></tr>
    @empty<tr><td colspan="5">No failed queue jobs.</td></tr>@endforelse
    </tbody></table></div>
</section>

<section>
    <h2>Failed report imports</h2>
    <div class="table-wrap"><table><thead><tr><th>Operation / entity</th><th>Attempts</th><th>Latest error</th><th>Latest attempt</th><th>Retryability / resolution</th></tr></thead><tbody>
    @forelse($failedImports as $import)
        <tr><td>{{ $import->import_type }}<br><code>{{ $import->id }}</code><br><span class="muted">{{ $import->connection?->name ?: $import->report_source_connection_id }}</span></td><td>{{ $import->attempt_count }}</td><td>{{ $import->safe_error ?: 'Import failed without a stored error message.' }}</td><td>{{ $import->completed_at ?: $import->started_at ?: $import->updated_at }}</td><td>@if($import->next_retry_at)Retry scheduled {{ $import->next_retry_at }}@elseNo retry exposed here; use Reporting workflow.@endif</td></tr>
    @empty<tr><td colspan="5">No failed imports.</td></tr>@endforelse
    </tbody></table></div>
</section>

@php
$controlTargets = [
    'PLATFORM' => [],
    'SITE' => $sites->map(fn($item) => ['id' => $item->id, 'label' => $item->display_name])->values(),
    'PLACEMENT' => $placements->map(fn($item) => ['id' => $item->id, 'label' => $item->name])->values(),
    'GAM_CONNECTION' => $gamConnections->map(fn($item) => ['id' => $item->id, 'label' => $item->name.' · '.$item->type->value])->values(),
    'DEMAND_NETWORK' => $demandNetworks->map(fn($item) => ['id' => $item->id, 'label' => $item->name])->values(),
];
@endphp
<script>
(() => {
    const controls = @json($allowedControls);
    const targets = @json($controlTargets);
    const scope = document.getElementById('control-scope');
    const target = document.getElementById('control-target');
    const scopeId = document.getElementById('control-scope-id');
    const key = document.getElementById('control-key');
    const state = document.getElementById('control-state');
    const impact = document.getElementById('platform-impact');
    const impactConfirmation = document.getElementById('impact-confirmation');
    const platformConfirmations = {
        AD_SERVING: 'DISABLE PLATFORM AD SERVING',
        GAM: 'DISABLE PLATFORM GAM',
        PREBID: 'DISABLE PLATFORM PREBID',
        DIRECT_JS: 'DISABLE PLATFORM DIRECT JS',
        NATIVE_DEMAND: 'DISABLE PLATFORM DIRECT JS',
    };

    const refresh = () => {
        const selectedScope = scope.value;
        target.innerHTML = '';
        if (selectedScope === 'PLATFORM') {
            target.add(new Option('Platform-wide / GLOBAL', ''));
            target.disabled = true;
            scopeId.value = '';
        } else {
            target.disabled = false;
            target.add(new Option('Select a target', ''));
            (targets[selectedScope] || []).forEach(item => target.add(new Option(item.label, item.id)));
            scopeId.value = target.value;
        }
        key.innerHTML = '';
        (controls[selectedScope] || []).forEach(item => key.add(new Option(item, item)));
        refreshImpact();
    };
    const refreshImpact = () => {
        const confirmation = scope.value === 'PLATFORM' && state.value === '1'
            ? platformConfirmations[key.value]
            : null;
        impact.hidden = !confirmation;
        impactConfirmation.required = Boolean(confirmation);
        impactConfirmation.placeholder = confirmation || '';
    };
    scope.addEventListener('change', refresh);
    target.addEventListener('change', () => scopeId.value = target.value);
    key.addEventListener('change', refreshImpact);
    state.addEventListener('change', refreshImpact);
    refresh();

    document.querySelectorAll('form.safe-submit').forEach(form => form.addEventListener('submit', () => {
        form.querySelectorAll('button[type="submit"], button:not([type])').forEach(button => {
            button.disabled = true;
            button.setAttribute('aria-disabled', 'true');
        });
    }));
})();
</script>
@endsection
