@extends('layouts.admin')
@section('title', 'Reconciliation')
@section('heading', 'Reconciliation')
@section('content')
@include('admin.finance._tabs')
<section class="hero"><div><p class="eyebrow">Source-to-ledger evidence</p><h2>Investigate discrepancies without rewriting history</h2><p>Expected source totals, normalized totals, differences, import state, and remediation notes remain traceable. Finalized finance is never silently changed to force a match.</p></div></section>
<form method="get" class="form-grid workspace-section"><label>Status<select name="status"><option value="">All</option>@foreach($reconciliationStatuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ str($status->value)->headline() }}</option>@endforeach</select></label><button class="hm-button-secondary">Filter</button></form>
<article class="workspace-section"><div class="table-wrap"><table><thead><tr><th>Source / Connection</th><th>Period / Import</th><th>Expected</th><th>Imported</th><th>Difference</th><th>Status / Warning</th><th>Remediation</th></tr></thead><tbody>
@forelse($runs as $run)
@php($gross = data_get($run->differences, 'gross_revenue_minor', []))
<tr><td><strong>{{ $run->connection?->source?->name }}</strong><span class="table-note">{{ $run->connection?->name }} · {{ $run->connection?->currency }}</span><span class="table-note">Last attempt {{ $run->connection?->last_attempted_at?->toDateTimeString() ?: '—' }}</span></td><td>{{ $run->period_start->toDateString() }} — {{ $run->period_end->toDateString() }}<span class="table-note">Import {{ $run->import?->id ?: '—' }} · {{ $run->import?->status?->value ?: '—' }}</span></td><td>{{ \App\Support\Money::formatMinor((int) ($gross['source'] ?? 0)) }}</td><td>{{ \App\Support\Money::formatMinor((int) ($gross['stored'] ?? 0)) }}</td><td>{{ \App\Support\Money::formatMinor((int) ($gross['difference'] ?? 0)) }}<span class="table-note">{{ number_format($run->discrepancy_basis_points) }} bp max</span><details><summary class="text-link">All metric differences</summary>@foreach($run->differences ?? [] as $metric => $difference)<span class="table-note">{{ str($metric)->headline() }}: {{ number_format((int) ($difference['source'] ?? 0)) }} → {{ number_format((int) ($difference['stored'] ?? 0)) }} ({{ number_format((int) ($difference['difference'] ?? 0)) }})</span>@endforeach</details></td><td><x-status-badge :status="$run->status" />
    @foreach($run->warnings ?? [] as $warning)<span class="table-note error">{{ $warning['code'] ?? 'WARNING' }}</span>@endforeach
    @if($run->error_message)<span class="table-note error">{{ $run->error_message }}</span>@endif
</td><td>
    @if($run->remediation_note)<span class="table-note">{{ $run->remediation_note }}</span><span class="table-note">{{ $run->remediator?->name }} · {{ $run->remediated_at?->toDateString() }}</span>@endif
    @if(auth()->user()->hasPermission('finance.reconciliation.manage'))<details><summary class="text-link">Record remediation</summary><form method="post" action="{{ route('admin.finance.reconciliation.remediate', $run) }}" class="form-stack">@csrf<label>Investigation/remediation note<textarea class="hm-input" name="remediation_note" required minlength="12" maxlength="2000"></textarea></label><button class="hm-button-secondary">Record without mutating totals</button></form></details>@endif
</td></tr>
@empty<tr><td colspan="7" class="muted">No reconciliation runs match the filter.</td></tr>@endforelse
</tbody></table></div></article>
{{ $runs->links() }}
<article class="workspace-section"><div class="workspace-heading"><div><p class="eyebrow">Retryable failures</p><h2>Failed report imports</h2></div></div>@forelse($failedImports as $import)<div class="compact-row"><div><strong>{{ $import->connection?->source?->name }} · {{ $import->connection?->name }}</strong><p>{{ $import->period_start }} — {{ $import->period_end }} · {{ $import->error_message }}</p></div>@if(auth()->user()->hasPermission('finance.reconciliation.manage'))<form method="post" action="{{ route('admin.finance.reconciliation.retry', $import) }}">@csrf<button class="hm-button-secondary">Retry import</button></form>@endif</div>@empty<p class="muted">No failed imports await retry.</p>@endforelse</article>
@endsection
