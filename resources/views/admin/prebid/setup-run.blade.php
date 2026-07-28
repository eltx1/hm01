@extends('layouts.admin')
@section('title', 'Prebid GAM setup')
@section('heading', 'Prebid GAM setup run')
@section('navigation')
<a href="{{ route('dashboard') }}">Dashboard</a>
<a href="{{ route('admin.gam.connections.index') }}">Google Ad Manager</a>
<a class="active" href="{{ route('admin.prebid.index') }}">Prebid</a>
@endsection
@section('content')
<section class="hero"><div><p class="eyebrow">{{ $run->connection->type->value }}</p><h2>{{ $run->connection->name }}</h2><p>{{ $run->template->name }} · version {{ $run->template->version }} · {{ $run->template->mode }}</p></div><span class="pill">{{ $run->status->value }}</span></section>

<div class="metric-grid" style="margin-top:1rem">
    <article><span class="muted">Total objects</span><strong class="metric">{{ data_get($run->plan, 'estimates.totalObjects', 0) }}</strong></article>
    <article><span class="muted">Pending at preview</span><strong class="metric">{{ data_get($run->plan, 'estimates.pendingObjects', 0) }}</strong></article>
    <article><span class="muted">Cursor</span><strong class="metric">{{ $run->cursor }}</strong></article>
    <article><span class="muted">Created</span><strong class="metric">{{ data_get($run->counters, 'created', 0) }}</strong></article>
    <article><span class="muted">Skipped</span><strong class="metric">{{ data_get($run->counters, 'skipped', 0) }}</strong></article>
    <article><span class="muted">Failed</span><strong class="metric">{{ data_get($run->counters, 'failed', 0) }}</strong></article>
</div>

<section class="detail-grid" style="margin-top:1rem">
<article><p class="eyebrow">Object estimate</p><h2>Planned GAM objects</h2><dl>@foreach(data_get($run->plan, 'estimates', []) as $key => $value)<dt>{{ str($key)->headline() }}</dt><dd>{{ $value }}</dd>@endforeach</dl></article>
<article><p class="eyebrow">Scope</p><h2>Selected delivery network</h2><dl><dt>Network</dt><dd>{{ data_get($run->plan, 'connection.networkCode') }}</dd><dt>Sites</dt><dd>{{ count(data_get($run->plan, 'siteKeys', [])) }}</dd><dt>Price points</dt><dd>{{ count(data_get($run->plan, 'pricePoints', [])) }}</dd><dt>Creative sizes</dt><dd>{{ count(data_get($run->plan, 'sizes', [])) }}</dd></dl></article>
</section>

@if(!data_get($run->plan, 'complete'))
<article class="danger-zone" style="margin-top:1rem"><p class="eyebrow">Setup blocked</p><h2>Missing prerequisites</h2><ul>@foreach(data_get($run->plan, 'missingPrerequisites', []) as $missing)<li>{{ $missing }}</li>@endforeach</ul><p>No external write can be performed until a new complete dry-run preview is created.</p></article>
@else
<article style="margin-top:1rem"><p class="eyebrow">Explicit write confirmation</p><h2>Execute a resumable batch</h2><p>This preview made no Google Ad Manager changes. The first batch requires the one-time confirmation code. Later batches resume from the saved cursor without repeating completed objects.</p>
@if($confirmationToken)<div class="domain-card"><strong>One-time confirmation code</strong><code class="installation-code">{{ $confirmationToken }}</code><p class="muted">This code is shown only after creating the dry-run preview.</p></div>@endif
<form class="inline-form" method="POST" action="{{ route('admin.prebid.setup-runs.execute', $run) }}">@csrf
    @if(!$run->confirmed_at)<input class="hm-input" name="confirmation_token" placeholder="Confirmation code" required value="{{ $confirmationToken }}">@endif
    <input class="hm-input" type="number" min="1" max="100" name="batch_limit" value="25" required>
    <button class="hm-button-primary">{{ $run->confirmed_at ? 'Resume setup batch' : 'Confirm and execute batch' }}</button>
</form></article>
@endif

<article style="margin-top:1rem"><p class="eyebrow">Remote object reconciliation</p><h2>Created or reused objects</h2><div class="table-wrap"><table><thead><tr><th>Object key</th><th>Type</th><th>Remote ID</th><th>Synced</th></tr></thead><tbody>@forelse($run->remoteObjects->sortBy('object_key') as $object)<tr><td>{{ $object->object_key }}</td><td>{{ $object->remote_object_type }}</td><td>{{ $object->remote_object_id }}</td><td>{{ $object->synced_at }}</td></tr>@empty<tr><td colspan="4" class="muted">No external objects have been written.</td></tr>@endforelse</tbody></table></div></article>

@if($run->errors->isNotEmpty())<article class="danger-zone"><p class="eyebrow">Errors</p><h2>Action required</h2>@foreach($run->errors as $error)<div class="event"><div><strong>{{ $error->category }} · {{ $error->code }}</strong><br><span>{{ $error->message }}</span></div><span>{{ $error->occurred_at }}</span></div>@endforeach</article>@endif
@endsection
