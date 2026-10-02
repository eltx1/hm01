@extends('layouts.admin')
@section('title', 'Historical GAM preview')
@section('heading', 'Historical GAM preview')
@section('content')
<div class="reports-page">
    <header class="report-page-heading"><div><p class="eyebrow">PRIVATE ADMIN REVIEW</p><h2>Historical revenue comparison</h2><p class="muted">Fresh Google Ad Exchange revenue for the selected ad unit and exact registered hostname, compared with stored daily facts and their original revenue-rule versions. Preview only: balances, imports, adjustments and payouts remain unchanged.</p></div></header>
    <p><a class="text-link" href="{{ route('admin.reporting.index') }}">Back to reports</a></p>
    @if($errors->any())<section class="workspace-section" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</section>@endif
    <article class="workspace-section"><h3>Choose a completed period</h3>
        <p>Up to 31 completed days, within the existing binding's effective dates. Google network timezone and USD apply. No parent-domain, sibling-site, other-unit or alternative-metric substitution is allowed.</p>
        <form method="POST" action="{{ route('admin.reporting.gam-comparison.start') }}" class="form-stack">
            @csrf
            <div><label for="gam-preview-site">Website</label><select id="gam-preview-site" class="hm-input" name="site_id" required>@foreach($sites as $site)<option value="{{ $site->id }}" @selected(old('site_id', data_get($selected, 'context.site_id')) === $site->id)>{{ $site->primary_domain }}</option>@endforeach</select></div>
            <div><label for="gam-preview-from">From</label><input id="gam-preview-from" class="hm-input" type="date" name="from" required value="{{ old('from', data_get($selected, 'context.from', now()->subMonthNoOverflow()->startOfMonth()->toDateString())) }}"></div>
            <div><label for="gam-preview-to">To</label><input id="gam-preview-to" class="hm-input" type="date" name="to" required value="{{ old('to', data_get($selected, 'context.to', now()->subMonthNoOverflow()->endOfMonth()->toDateString())) }}"></div>
            <button class="hm-button-primary">Request private preview</button>
        </form>
        <p class="muted">Three previews maximum, available only in this admin session for 30 minutes. Repeated requests reuse the same attempt. Failed or interrupted requests are not automatically restarted.</p>
    </article>
    @foreach($comparisons as $entry)
        <article class="workspace-section"><a href="{{ route('admin.reporting.gam-comparison', ['comparison' => $entry['id']]) }}">{{ $entry['context']['hostname'] }} · {{ $entry['context']['from'] }} to {{ $entry['context']['to'] }}</a>
            <form method="POST" action="{{ route('admin.reporting.gam-comparison.discard', $entry['id']) }}">@csrf<button class="hm-button-secondary">Discard preview</button></form>
        </article>
    @endforeach
    @if($selected)
        @php($context = $selected['context'])
        @php($job = $selected['job'])
        @php($money = fn ($minor) => $minor === null ? 'Unverified' : \App\Support\Money::formatMinor((int) $minor))
        <article class="workspace-section"><h3>{{ $context['hostname'] }}</h3>
            <p>{{ $context['from'] }} to {{ $context['to'] }} · {{ $context['timezone'] }} · USD · Ad Exchange · Network base {{ $context['network_currency'] }}</p>
            <p>Selected ad unit {{ $context['ad_unit_id'] }} · Captured {{ $context['captured_at'] }}</p>
            <p class="muted">Separate approved adjustments are excluded and unchanged. Projections retain each stored day's deductions and original rule version. Missing, mixed or closed-period evidence blocks allocation; this page does not establish approval or settlement safety.</p>
            @if($job['status'] === 'STARTING')
                <p role="alert">The request was interrupted or its outcome is uncertain. This attempt will not be submitted again. Discard it only if you intentionally want to request a new report.</p>
            @elseif($job['status'] === 'FAILED')
                <p class="breakable" role="alert">{{ $job['error'] }}. Preview unavailable or unverified. No alternative scope, currency or metric was substituted.</p>
            @elseif($job['status'] === 'PENDING')
                <p>Google is preparing the report. Wait at least 15 seconds between refreshes.</p>
                <form method="POST" action="{{ route('admin.reporting.gam-comparison.poll', $selected['id']) }}">@csrf<button class="hm-button-secondary">Refresh status</button></form>
            @else
                @php($result = $job['result'])
                <p role="note">{{ $result['basis_notice'] }} Projections show the proposed Ad Exchange allocation using the original shares; no replacement is approved.</p>
                <p>Retrieved {{ $job['completed_at'] }}. {{ $result['excluded_site_rows'] }} nonmatching Site rows excluded.</p>
                @if(!$result['exact_site_observed'])<p role="alert">No exact Site row was observed. Missing evidence is not zero revenue.</p>@endif
                <a class="hm-button-secondary" href="{{ route('admin.reporting.gam-comparison.download', $selected['id']) }}">Download private preview JSON</a>
                <div class="table-wrap"><table><caption>Daily gross revenue and allocation comparison (USD)</caption><thead><tr><th>Date</th><th>Stored gross</th><th>Exact-site AdX gross</th><th>Observed difference</th><th>Stored / fresh impressions</th><th>Original-rule allocation</th><th>Review flags</th></tr></thead><tbody>
                @foreach($result['days'] as $day)
                    <tr><td>{{ $day['date'] }}</td><td>{{ $money(data_get($day, 'stored.gross_revenue_minor')) }}<br><small>{{ implode(', ', $day['stored_metric_bases']) ?: 'No stored evidence' }}</small></td><td>{{ $money(data_get($day, 'fresh.gross_revenue_minor')) }}</td><td>{{ $money($day['gross_delta_minor']) }}</td><td>{{ data_get($day, 'stored.impressions', 'Unverified') }} / {{ data_get($day, 'fresh.impressions', 'Unverified') }}</td><td>
                    @if($day['projected'])
                        <p>Publisher {{ $money($day['stored']['publisher_earnings_minor']) }} → {{ $money($day['projected']['publisher_earnings_minor']) }}</p>
                        <p>Horus {{ $money($day['stored']['horus_earnings_minor']) }} → {{ $money($day['projected']['horus_earnings_minor']) }}</p>
                        <p>MCM {{ $money($day['stored']['mcm_partner_earnings_minor']) }} → {{ $money($day['projected']['mcm_partner_earnings_minor']) }}</p>
                    @else Withheld pending review @endif
                    </td><td>{{ $day['flags'] ? implode(', ', $day['flags']) : 'Original-rule projection only; no apply approval' }} @if($day['basis_change'])<p>Metric basis changed or unversioned; difference is not scope-only</p>@endif</td></tr>
                @endforeach
                </tbody></table></div>
                <p class="muted">Raw revenue micros are preserved in the private download. Daily USD amounts use the same signed half-cent rounding as the importer; publisher and MCM allocations round down, with the remainder assigned to Horus.</p>
            @endif
        </article>
    @endif
</div>
@endsection
