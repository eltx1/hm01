@props(['current' => 'FORMAT', 'context' => []])
<nav class="report-shortcuts report-breakdown-selector" aria-label="Breakdown by">
    <span class="muted">Breakdown by</span>
    @foreach(['FORMAT' => ['format', 'Ad format'], 'COUNTRY' => ['country', 'Country'], 'DEVICE' => ['device', 'Device']] as $key => [$param, $label])
        <a class="pill" href="{{ request()->url() }}?{{ http_build_query(array_filter(array_merge($context, ['by' => $key === 'FORMAT' ? null : $param]), fn ($value) => $value !== null && $value !== '')) }}" @if($current === $key)aria-current="true"@endif>{{ $label }}</a>
    @endforeach
</nav>
