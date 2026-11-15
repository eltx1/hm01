@extends('layouts.admin')
@section('title', 'Website performance observations')
@section('heading', 'Website performance observations')
@section('content')
<section>
    <h2>{{ $site->display_name }}</h2>
    <a href="{{ route('admin.sites.show', $site) }}">Return to website</a>
    <p>Sampled measurements after verified Cloudflare PASS. Disabled by default. Collection never grants or denies advertising permission.</p>
    <p class="muted">Render and post-PASS request timings currently cover GPT display slots. Prebid, Direct JS and video without GPT evidence remain incomplete, not failed ads. These samples are not a fraud score or financial records.</p>
    @if(session('status'))<p>{{ session('status') }}</p>@endif
    @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
    <p>Collection infrastructure: {{ $configured ? 'Configured; live delivery must be verified separately' : 'Not configured; collection is disabled' }}</p>
    @if(auth()->user()->hasPermission('configs.manage'))
    <form class="form-stack safe-submit" method="POST" action="{{ route('admin.sites.performance.update', $site) }}">@csrf @method('PUT')
        <label>Collection<select name="enabled" class="hm-select"><option value="0" @selected(!($settings['enabled'] ?? false))>Disabled</option><option value="1" @selected($settings['enabled'] ?? false) @disabled(!$configured)>Enabled for this website</option></select></label>
        <label>Sampling rate (basis points; 100 = 1%)<input class="hm-input" type="number" name="sample_bp" min="1" max="1000" value="{{ $settings['sampleBp'] ?? 100 }}" required></label>
        <button class="hm-button-primary">Save observation settings</button>
    </form>
    @endif
</section>
<section>
    <h2>Last 24 hours · current confirmed production version {{ $report['configuration_version'] ?? '—' }}</h2>
    <p>State: {{ $report['state'] }} · Read at {{ $report['as_of_utc'] }} UTC</p>
    <p class="muted">Stored rows are the records read by Analytics Engine. Samples correct for its internal sampling; estimated page observations also account for the configured browser sampling rate. Low coverage limits comparisons. Versions are never silently combined.</p>
    <div class="table-wrap"><table><thead><tr><th>Device</th><th>Browser</th><th>Measurement</th><th>Stored rows</th><th>Adjusted samples</th><th>Estimated observations</th><th>p50 ms</th><th>p75 ms</th><th>p95 ms</th></tr></thead><tbody>
        @forelse($report['rows'] as $row)
        <tr><td>{{ $row['device'] }}</td><td>{{ $row['browser'] }}</td><td>{{ ['config' => 'Configuration fetch', 'cloudflare_total' => 'Cloudflare decision including retries', 'first_ad' => 'Horus init → first GPT nonempty render', 'after_pass' => 'PASS → first GPT request'][$row['metric']] ?? $row['metric'] }}</td><td>{{ number_format($row['stored_rows']) }}</td><td>{{ number_format($row['samples']) }}</td><td>{{ number_format($row['estimated_events']) }}</td>
        @foreach(['p50_ms','p75_ms','p95_ms'] as $metric)<td>{{ in_array($row['metric'], ['config','cloudflare_total','first_ad','after_pass'], true) ? number_format($row[$metric]) : '—' }}</td>@endforeach</tr>
        @empty<tr><td colspan="9">No usable observations are available. Missing observations do not mean zero latency or healthy delivery.</td></tr>@endforelse
    </tbody></table></div>
</section>
@endsection
