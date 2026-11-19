@props(['metric', 'current', 'previous' => null, 'period' => null])
@php($delta = \App\Support\PeriodComparison::delta($metric, $current, $previous))
@if($delta)
<span class="report-delta report-delta--{{ $delta['direction'] }}" @if($period) title="vs {{ \Carbon\CarbonImmutable::parse($period['from'])->format('j M') }} – {{ \Carbon\CarbonImmutable::parse($period['to'])->format('j M Y') }}" @endif>
    <span aria-hidden="true">{{ ['up' => '▲', 'down' => '▼', 'flat' => '■'][$delta['direction']] }}</span>
    {{ $delta['text'] }}<span class="sr-only"> vs previous period</span>
</span>
@endif
