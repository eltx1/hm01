@props(['from', 'to', 'metrics'])
<div class="publisher-report-period">
    <nav class="report-shortcuts" aria-label="Quick reporting periods">
        @foreach([
            ['Today', now()->toDateString(), now()->toDateString()],
            ['Yesterday', now()->subDay()->toDateString(), now()->subDay()->toDateString()],
            ['Last 7 days', now()->subDays(6)->toDateString(), now()->toDateString()],
            ['This month', now()->startOfMonth()->toDateString(), now()->toDateString()],
            ['Last month', now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
        ] as [$label, $start, $end])
            <a class="pill" href="{{ request()->url() }}?{{ http_build_query(['from' => $start, 'to' => $end, 'metrics' => $metrics]) }}" @if($from === $start && $to === $end)aria-current="true"@endif>{{ $label }}</a>
        @endforeach
    </nav>
    <form method="get" id="publisher-report-filter" aria-label="Reporting period">
        <input type="hidden" name="customize" value="1">
        <details class="publisher-date-picker">
            <summary><span>{{ \Carbon\CarbonImmutable::parse($from)->format('j M Y') }} – {{ \Carbon\CarbonImmutable::parse($to)->format('j M Y') }}</span><span class="text-link">Change dates</span></summary>
            <div class="report-filter">
                <label>From<input class="hm-input" type="date" name="from" value="{{ $from }}" required></label>
                <label>To<input class="hm-input" type="date" name="to" value="{{ $to }}" required></label>
                <button class="hm-button-primary" type="submit">Update report</button>
            </div>
        </details>
    </form>
</div>
