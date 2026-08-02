@extends('layouts.admin')
@section('title', 'Production operations')
@section('heading', 'Production operations')
@section('navigation')
<a href="{{ route('dashboard') }}">Overview</a><a class="active" href="{{ route('admin.operations.index') }}">Operations</a>@if(auth()->user()->hasPermission('reporting.admin.view'))<a href="{{ route('admin.reporting.index') }}">Reporting</a>@endif
@endsection
@section('content')
<section class="metric-grid">
<article><p class="eyebrow">Scheduler heartbeat</p><strong class="metric">{{ $heartbeatStale ? 'STALE' : 'HEALTHY' }}</strong><p class="muted">{{ $heartbeat?->last_seen_at ?: 'Never recorded' }}</p></article>
<article><p class="eyebrow">Failed jobs</p><strong class="metric">{{ $failedJobs->count() }}</strong></article>
<article><p class="eyebrow">Failed imports</p><strong class="metric">{{ $failedImports->count() }}</strong></article>
<article><p class="eyebrow">Active kill switches</p><strong class="metric">{{ $controls->where('is_disabled', true)->count() }}</strong></article>
</section>
<section class="metric-grid">
<article><p class="eyebrow">Pilot go/no-go</p><strong class="metric">{{ $pilotReady ? 'GO' : 'NO-GO' }}</strong><p class="muted">Requires fresh passing CDN probes, scheduler heartbeat, and no failed delivery.</p></article>
<article><p class="eyebrow">Synthetic coverage</p><strong class="metric">{{ $latestProbes->where('status', 'PASS')->count() }}/{{ $latestProbes->count() }}</strong><p class="muted">No per-impression telemetry reaches Hostinger.</p></article>
</section>
<section><h2>Static runtime probes</h2><div class="table-wrap"><table><thead><tr><th>Site</th><th>Status</th><th>Latency</th><th>Observed</th></tr></thead><tbody>
@forelse($latestProbes as $probe)<tr><td>{{ $probe->site_id }}</td><td>{{ $probe->status }}</td><td>{{ $probe->latency_ms }} ms</td><td>{{ $probe->observed_at }}</td></tr>@empty<tr><td colspan="4">Run <code>php artisan adtech:probe</code> before pilot approval.</td></tr>@endforelse
</tbody></table></div></section>
<section class="metric-grid">
<article><p class="eyebrow">Edge deployment</p><strong class="metric">{{ $latestDelivery ? 'DEPLOYED' : 'NONE' }}</strong><p class="muted">{{ $latestDelivery?->deployed_at ?: 'No confirmed Pages deployment' }} · {{ $latestDelivery?->file_count ?? 0 }}/{{ $deliveryFileLimit }} files @if(($latestDelivery?->file_count ?? 0) >= $deliveryFileWarning) · WARNING @endif</p></article>
<article><p class="eyebrow">Pending delivery</p><strong class="metric">{{ $pendingDeliveries }}</strong><p class="muted">Batched by the scheduler</p></article>
<article><p class="eyebrow">Failed delivery</p><strong class="metric">{{ $failedDeliveries }}</strong><p class="muted">Requires retry or investigation</p></article>
<article><p class="eyebrow">Monthly safety budget</p><strong class="metric">{{ max(0, $deliveryBudget - $deliveryBudgetUsed) }}</strong><p class="muted">{{ $deliveryBudgetUsed }} used of configured {{ $deliveryBudget }} · {{ $urgentDeliveries }} urgent</p></article>
</section>
<section><h2>Cloudflare Pages static delivery</h2><p class="muted">A batch is marked deployed only after the Pages workflow succeeds. Publisher runtime traffic is served by static edge files; it never enters this dashboard application.</p>
<div class="table-wrap"><table><thead><tr><th>Batch</th><th>Status</th><th>Manifest</th><th>Remote evidence</th><th>Age / retry</th></tr></thead><tbody>
@forelse($deliveryBatches as $batch)<tr><td><code>{{ $batch->id }}</code><br><span class="muted">{{ $batch->priority->value }} · {{ $batch->item_count }} items · {{ $batch->file_count }} files</span></td><td>{{ str_replace('_', ' ', $batch->status->value) }}@if($batch->error_code)<br><span class="muted">{{ $batch->error_code }}: {{ $batch->error_message }}</span>@endif</td><td><code>{{ $batch->manifest_hash ? substr($batch->manifest_hash, 0, 16).'…' : 'pending' }}</code></td><td>@if($batch->remote_url)<a href="{{ $batch->remote_url }}" rel="noopener noreferrer">{{ $batch->remote_deployment_id ?: 'Open evidence' }}</a>@else{{ $batch->remote_deployment_id ?: 'Not submitted' }}@endif</td><td>{{ $batch->deployed_at ?: $batch->submitted_at ?: $batch->created_at }}@if(in_array($batch->status->value, ['FAILED', 'RETRY_SCHEDULED'], true))<form class="inline" method="POST" action="{{ route('admin.operations.static-delivery.retry', $batch) }}">@csrf<input type="password" name="current_password" required placeholder="Password"><button>Retry</button></form>@endif</td></tr>
@empty<tr><td colspan="5">No static delivery batches yet.</td></tr>@endforelse
</tbody></table></div></section>
<section class="split-grid">
<article><h2>Operational kill switch</h2><p class="muted">Changes require your current password and are written to the audit log. HORUS_GAM remains the default route whenever its serving control is enabled.</p>
<form method="POST" action="{{ route('admin.operations.controls') }}">@csrf
<label>Scope<select name="scope_type"><option>PLATFORM</option><option>SITE</option><option>PLACEMENT</option><option>GAM_CONNECTION</option></select></label>
<label>Scope ID<input name="scope_id" placeholder="Leave empty only for PLATFORM"></label>
<label>Control<select name="control_key"><option>AD_SERVING</option><option>PREBID</option><option>NATIVE_DEMAND</option></select></label>
<label>State<select name="is_disabled"><option value="1">Disabled</option><option value="0">Enabled</option></select></label>
<label>Reason<textarea name="reason" required minlength="8"></textarea></label><label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label><button>Apply audited control</button></form></article>
<article><h2>Loader rollback</h2><form method="POST" action="{{ route('admin.operations.loader.rollback') }}">@csrf<label>Release<select name="loader_release_id" required>@foreach($loaderReleases as $release)<option value="{{ $release->id }}">{{ $release->version }} {{ $release->is_active ? '(active)' : '' }}</option>@endforeach</select></label><label>Current password<input type="password" name="current_password" required></label><button>Activate release</button></form>
<h2>Current controls</h2>@forelse($controls as $control)<div class="event"><strong>{{ $control->scope_type }} / {{ $control->control_key }}</strong><span>{{ $control->is_disabled ? 'DISABLED' : 'ENABLED' }} · {{ $control->scope_id ?: 'global' }}</span></div>@empty<p class="muted">No overrides recorded.</p>@endforelse</article>
</section>
<section><h2>Failed jobs</h2><div class="table-wrap"><table><thead><tr><th>UUID</th><th>Queue</th><th>Failed</th><th>Actions</th></tr></thead><tbody>@forelse($failedJobs as $job)<tr><td><code>{{ $job->uuid }}</code></td><td>{{ $job->queue }}</td><td>{{ $job->failed_at }}</td><td><form class="inline" method="POST" action="{{ route('admin.operations.jobs.retry', $job->uuid) }}">@csrf<input type="password" name="current_password" required placeholder="Password"><button>Retry</button></form><form class="inline" method="POST" action="{{ route('admin.operations.jobs.forget', $job->uuid) }}">@csrf @method('DELETE')<input type="password" name="current_password" required placeholder="Password"><button>Forget</button></form></td></tr>@empty<tr><td colspan="4">No failed jobs.</td></tr>@endforelse</tbody></table></div></section>
<section><h2>Failed report imports</h2>@forelse($failedImports as $import)<div class="event"><strong>{{ $import->id }}</strong><span>{{ $import->error_message ?: 'Import failed' }}</span></div>@empty<p class="muted">No failed imports.</p>@endforelse</section>
@endsection
