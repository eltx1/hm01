@php($reportBinding = $site->currentGamReportBinding)
<article id="reporting" class="workspace-section">
    @if($todayReport ?? null)
        <section id="today-report" aria-labelledby="today-report-heading">
            <div class="workspace-heading"><div><p class="eyebrow">{{ $site->primary_domain }} · Estimated performance</p><h2 id="today-report-heading">Today so far</h2><p class="muted">{{ $todayReport['date'] }} · {{ $todayReport['timezone'] }} · {{ $todayReport['currency'] }}</p></div><span class="pill">Estimated</span></div>
            @if($todayReport['available'])
                <div class="metric-grid">
                    @foreach([
                        ['Ad requests', number_format($todayReport['ad_requests'])],
                        ['Impressions', number_format($todayReport['impressions'])],
                        ['Clicks', number_format($todayReport['clicks'])],
                        ['Estimated gross revenue', \App\Support\Money::formatMinor($todayReport['gross_revenue_minor']).' '.$todayReport['currency']],
                        ['Estimated publisher earnings', \App\Support\Money::formatMinor($todayReport['publisher_earnings_minor']).' '.$todayReport['currency']],
                    ] as [$label, $value])
                        <article><p class="eyebrow">{{ $label }}</p><strong class="metric">{{ $value }}</strong></article>
                    @endforeach
                </div>
                <p>Last imported: {{ $todayReport['updated_at'] }} · {{ $todayReport['timezone'] }}</p>
            @else
                <p role="status">Today's report has not arrived yet. Figures will appear after the next successful automatic import.</p>
            @endif
            <p class="muted">{{ $todayReport['refresh_enabled'] ? 'Updated automatically every hour. Google data may be delayed.' : 'Automatic refresh is paused for this reporting connection.' }} These are the latest imported estimates, not live counters or finalized payout amounts.</p>
            @if(auth()->user()->hasPermission('reporting.admin.view'))
                <a class="hm-button-secondary button-link" href="{{ route('admin.reporting.index', ['currency' => $todayReport['currency']]) }}">View completed-day reports</a>
            @endif
        </section>
    @endif
    <div class="workspace-heading"><div><p class="eyebrow">Website reporting</p><h2>Connect an Ad Manager ad unit</h2><p class="muted">Choose an account and an ad unit. Reports, publisher revenue share and financial statements use this website's reporting source automatically.</p></div></div>
    @if($reportBinding)
        <div class="compact-row"><div><strong>{{ $reportBinding->gamConnection?->name }} · {{ $reportBinding->ad_unit_name }}</strong><p>Network {{ $reportBinding->network_code }} · Unit {{ $reportBinding->ad_unit_id }} · From {{ $reportBinding->starts_on->toDateString() }}</p></div><x-status-badge :status="$reportBinding->connection->is_enabled ? $reportBinding->connection->status : 'DISABLED'" /></div>
        <dl>
            <dt>Reporting currency</dt><dd>{{ $reportBinding->connection->currency }} · Horus platform standard</dd>
            <dt>Google network currency</dt><dd>{{ data_get($reportBinding->connection->configuration, 'network_currency', 'Unknown') }} · source metadata only</dd>
            <dt>Reporting timezone</dt><dd>{{ $reportBinding->connection->timezone }}</dd>
            <dt>Last successful import</dt><dd>{{ $reportBinding->connection->last_successful_import_at ?? 'Waiting for the first automatic synchronization' }}</dd>
            <dt>Last finalized import</dt><dd>{{ $reportBinding->connection->last_finalized_import_at ?? 'Pending' }}</dd>
        </dl>
        @if($reportBinding->connection->last_error)<p role="alert">Latest refresh failed: {{ $reportBinding->connection->last_error }}</p><p class="muted">The timestamps above show earlier successful imports. Their data is preserved while the failed refresh retries automatically.</p>@endif
    @else
        <p class="muted">Current reporting: existing GAM or CSV sources. Connect below to make the selected ad unit this website's reporting source.</p>
    @endif
    @if(auth()->user()->hasPermission('gam.connections.manage'))
        @if($reportingGamConnections->isEmpty())
            <h3>Connect your first Ad Manager account</h3>
            @include('admin.gam.reporting-google-button', ['googleReady' => $reportingGoogleReady])
        @else
            <details><summary>Connect another Ad Manager account</summary>
                @include('admin.gam.reporting-google-button', ['googleReady' => $reportingGoogleReady])
            </details>
        @endif
    @endif
    @if($reportingGamConnections->isNotEmpty())
        <form method="POST" action="{{ route('admin.sites.reporting.gam.store', $site) }}" class="form-stack" data-gam-report-binding data-units-url="{{ route('admin.sites.reporting.gam.units', $site) }}">
            @csrf
            <label>Ad Manager account<select class="hm-input" name="gam_connection_id" required>
                <option value="">Choose an account</option>
                @foreach($reportingGamConnections as $connection)<option value="{{ $connection->id }}" @selected(old('gam_connection_id', session('reporting_gam_connection_id', $reportBinding?->gam_connection_id ?? ($reportingGamConnections->count() === 1 ? $connection->id : null))) === $connection->id)>{{ $connection->name }} · {{ $connection->network_code }}</option>@endforeach
            </select></label>
            <label>Ad unit<input class="hm-input" name="ad_unit" list="gam-report-unit-options" value="{{ old('ad_unit', session()->has('reporting_gam_connection_id') && session('reporting_gam_connection_id') !== $reportBinding?->gam_connection_id ? '' : $reportBinding?->ad_unit_id) }}" placeholder="Search by name, code or ID" autocomplete="off" maxlength="255" required aria-describedby="gam-report-unit-help"></label>
            <datalist id="gam-report-unit-options"></datalist>
            <small id="gam-report-unit-help" data-unit-feedback aria-live="polite">Select a search result or enter the exact name, code or ID.</small>
            <p class="muted">Reports cover the selected unit only, excluding child units. Horus always requests Google reporting in USD; the network's native currency is retained as metadata only. The reporting timezone follows the Google network. The first import covers the current open month, starting after any days already imported for this website. Existing closed financial history is preserved. Ad delivery settings stay as configured.</p>
            <button class="hm-button-primary">{{ $reportBinding ? 'Update reporting connection' : 'Connect reports' }}</button>
        </form>
        <p class="muted">Synchronization checks every five minutes. Today's daily totals are estimates refreshed every hour; completed days refresh every six hours while the financial period is open. Google may take time to prepare a report.</p>
    @endif
</article>
