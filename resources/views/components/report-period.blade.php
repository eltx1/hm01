@props(['from', 'to'])
@php
    $fromValue = is_string($from) ? $from : $from->toDateString();
    $toValue = is_string($to) ? $to : $to->toDateString();
@endphp
<div class="report-period workspace-section">
    <form method="get" class="report-filter" aria-label="Reporting period">
        <label>From<input class="hm-input" type="date" name="from" value="{{ $fromValue }}" required></label>
        <label>To<input class="hm-input" type="date" name="to" value="{{ $toValue }}" required></label>
        <button class="hm-button-primary" type="submit">Update report</button>
    </form>
    <nav class="report-shortcuts" aria-label="Quick reporting periods">
        @foreach([
            ['Last 7 days', now()->subDays(6)->toDateString(), now()->toDateString()],
            ['This month', now()->startOfMonth()->toDateString(), now()->toDateString()],
            ['Last month', now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
        ] as [$label, $start, $end])
            <a class="pill" href="{{ request()->url() }}?{{ http_build_query(['from' => $start, 'to' => $end]) }}" @if($fromValue === $start && $toValue === $end)aria-current="true"@endif>{{ $label }}</a>
        @endforeach
    </nav>
</div>
