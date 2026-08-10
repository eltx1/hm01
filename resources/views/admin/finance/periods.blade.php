@extends('layouts.admin')
@section('title', 'Financial Periods')
@section('heading', 'Financial Periods')
@section('content')
@include('admin.finance._tabs')
<section class="hero"><div><p class="eyebrow">Readiness-gated close</p><h2>Close only complete, reconciled periods</h2><p>Finalized data, import health, reconciliation, and adjustment decisions are checked under a database lock before monthly snapshots and statements are produced.</p></div></section>
<article class="workspace-section"><div class="table-wrap"><table>
    <thead><tr><th>Period</th><th>Status</th><th>Readiness</th><th>Evidence</th><th>Close action</th></tr></thead>
    <tbody>
    @forelse($periodRows as $row)
        @php($period = $row['period'])
        @php($readiness = $row['readiness'])
        <tr>
            <td><strong>{{ $period->period_key }} · {{ $period->currency }}</strong><span class="table-note">{{ $period->starts_on->toDateString() }} — {{ $period->ends_on->toDateString() }}</span></td>
            <td><x-status-badge :status="$period->status" /></td>
            <td><x-status-badge :status="$period->isClosed() ? 'CLOSED' : ($readiness['ready'] ? 'READY' : 'BLOCKED')" />
                @foreach($readiness['blockers'] ?? [] as $blocker)<span class="table-note">{{ $blocker['code'] }} ({{ $blocker['count'] }})</span>@endforeach
            </td>
            <td>
                @foreach(($readiness['counts'] ?? []) as $label => $count)<span class="table-note">{{ str($label)->headline() }}: {{ number_format($count) }}</span>@endforeach
                @if($period->close_override_reason)<span class="table-note error">Override: {{ $period->close_override_reason }}</span>@endif
            </td>
            <td>
                @if(!$period->isClosed() && auth()->user()->hasPermission('finance.periods.close'))
                    @if($readiness['ready'])
                        <form method="post" action="{{ route('admin.finance.periods.close', $period) }}" class="inline-form">@csrf<input type="hidden" name="confirm_close" value="1"><button class="hm-button-primary">Close ready period</button></form>
                    @elseif(auth()->user()->hasPermission('finance.periods.override'))
                        <details><summary class="text-link">Privileged override</summary><form method="post" action="{{ route('admin.finance.periods.close', $period) }}" class="form-stack">@csrf<input type="hidden" name="confirm_close" value="1"><input type="hidden" name="override_close" value="1"><label>Required reason<textarea class="hm-input" name="override_reason" required minlength="12" maxlength="2000"></textarea></label><button class="hm-button-danger">Override blockers and close</button></form></details>
                    @else<span class="muted">Resolve blockers before close.</span>@endif
                @else<span class="muted">No action available.</span>@endif
            </td>
        </tr>
    @empty<tr><td colspan="5" class="muted">Periods open automatically when aggregated reports arrive.</td></tr>@endforelse
    </tbody>
</table></div></article>
@endsection
