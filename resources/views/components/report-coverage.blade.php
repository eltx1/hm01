@props(['coverage' => [], 'channel' => 'Main', 'pageScoped' => false])
@if(($coverage['expected_count'] ?? 0) > 0)
<aside class="report-reconciliation" aria-label="{{ $channel }} website reporting coverage">
    <h3>{{ $channel }} website reporting {{ $coverage['missing_count'] > 0 ? ($coverage['reported_count'] > 0 ? 'is partial' : 'is awaiting data') : 'has imported data' }}</h3>
    <p>{{ $coverage['reported_count'] }} of {{ $coverage['expected_count'] }} active website GAM sources{{ $pageScoped ? ' on this page' : '' }} have imported data for their latest selected reporting day. Intraday results may still change.</p>
    @if($coverage['missing_count'] > 0)
    <p>Totals reflect only the data received. Missing website data does not mean zero earnings.</p>
    <ul>@foreach($coverage['pending_sites'] as $pending)<li>{{ $pending['label'] }} · {{ $pending['date'] }} ({{ $pending['timezone'] }}) · {{ $pending['state'] === 'not_started' ? 'Reporting day has not started in this timezone' : 'Awaiting imported data' }}</li>@endforeach</ul>
    @endif
    <p class="muted">Coverage checks the latest selected day for each source, not every day in the period.</p>
</aside>
@endif
