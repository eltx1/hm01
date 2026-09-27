@props(['metrics'])
<div class="publisher-report-tools">
    <details class="report-column-picker">
        <summary>Customize columns <span>{{ count($metrics) }} selected</span></summary>
        <fieldset><legend>Daily and website details</legend>
            @foreach(\App\Services\Reporting\PerformanceMetrics::COLUMNS as $key => $label)
                <label><input form="publisher-report-filter" type="checkbox" name="metrics[]" value="{{ $key }}" @checked(in_array($key, $metrics, true))> {{ $label }}</label>
            @endforeach
        </fieldset>
        <button class="hm-button-secondary" form="publisher-report-filter" type="submit">Apply columns</button>
    </details>
    <button class="hm-button-secondary" form="publisher-report-filter" type="submit" name="export" value="csv">Download CSV</button>
</div>
