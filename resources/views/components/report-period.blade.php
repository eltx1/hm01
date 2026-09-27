@props(['from', 'to', 'metrics' => null, 'exportable' => true, 'context' => []])
@php
    $fromValue = is_string($from) ? $from : $from->toDateString();
    $toValue = is_string($to) ? $to : $to->toDateString();
@endphp
<div class="report-period workspace-section">
    <form method="get" class="report-filter" aria-label="Reporting period">
        @foreach($context as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
        <label>From<input class="hm-input" type="date" name="from" value="{{ $fromValue }}" required></label>
        <label>To<input class="hm-input" type="date" name="to" value="{{ $toValue }}" required></label>
        <button class="hm-button-primary" type="submit">Update report</button>
        @if($metrics !== null && $exportable)<button class="hm-button-secondary" type="submit" name="export" value="csv">Download CSV</button>@endif
        @if($metrics !== null)
        <input type="hidden" name="customize" value="1">
        <details class="report-column-picker"><summary>Customize columns <span>{{ count($metrics) }} selected</span></summary>
            <fieldset><legend>Performance columns</legend>
                @foreach(\App\Services\Reporting\PerformanceMetrics::COLUMNS as $key => $label)
                <label><input type="checkbox" name="metrics[]" value="{{ $key }}" @checked(in_array($key, $metrics, true))> {{ $label }}</label>
                @endforeach
            </fieldset>
            <p class="muted">Choose columns, then update your report. Earnings always stay visible.</p>
        </details>
        @endif
    </form>
    <nav class="report-shortcuts" aria-label="Quick reporting periods">
        @foreach([
            ['Today', now()->toDateString(), now()->toDateString()],
            ['Yesterday', now()->subDay()->toDateString(), now()->subDay()->toDateString()],
            ['Last 7 days', now()->subDays(6)->toDateString(), now()->toDateString()],
            ['This month', now()->startOfMonth()->toDateString(), now()->toDateString()],
            ['Last month', now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
        ] as [$label, $start, $end])
            <a class="pill" href="{{ request()->url() }}?{{ http_build_query(['from' => $start, 'to' => $end] + $context + ($metrics === null ? [] : ['metrics' => $metrics])) }}" @if($fromValue === $start && $toValue === $end)aria-current="true"@endif>{{ $label }}</a>
        @endforeach
    </nav>
</div>
