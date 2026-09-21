@php($reportBinding = $site->currentGamReportBinding)
<article id="reporting" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Website reporting</p><h2>Connect an Ad Manager ad unit</h2><p class="muted">Choose an account and an ad unit. Reports, publisher revenue share and financial statements use this website's reporting source automatically.</p></div></div>
    @if($reportBinding)
        <div class="compact-row"><div><strong>{{ $reportBinding->gamConnection?->name }} · {{ $reportBinding->ad_unit_name }}</strong><p>Network {{ $reportBinding->network_code }} · Unit {{ $reportBinding->ad_unit_id }} · From {{ $reportBinding->starts_on->toDateString() }}</p></div><x-status-badge :status="$reportBinding->connection->is_enabled ? $reportBinding->connection->status : 'DISABLED'" /></div>
        <dl><dt>Currency / timezone</dt><dd>{{ $reportBinding->connection->currency }} · {{ $reportBinding->connection->timezone }}</dd><dt>Last successful import</dt><dd>{{ $reportBinding->connection->last_successful_import_at ?? 'Waiting for the first automatic synchronization' }}</dd><dt>Last finalized import</dt><dd>{{ $reportBinding->connection->last_finalized_import_at ?? 'Pending' }}</dd></dl>
        @if($reportBinding->connection->last_error)<p role="alert">{{ $reportBinding->connection->last_error }}</p>@endif
    @else
        <p class="muted">Current reporting: existing GAM or CSV sources. Connect below to make the selected ad unit this website's reporting source.</p>
    @endif
    @if($reportingGamConnections->isEmpty())
        <p>No active Google Ad Manager account is available for this publisher. Add a connection in Google Ad Manager settings first.</p>
    @else
        <form method="POST" action="{{ route('admin.sites.reporting.gam.store', $site) }}" class="form-stack" data-gam-report-binding data-units-url="{{ route('admin.sites.reporting.gam.units', $site) }}">
            @csrf
            <label>Ad Manager account<select class="hm-input" name="gam_connection_id" required>
                <option value="">Choose an account</option>
                @foreach($reportingGamConnections as $connection)<option value="{{ $connection->id }}" @selected(old('gam_connection_id', $reportBinding?->gam_connection_id ?? ($reportingGamConnections->count() === 1 ? $connection->id : null)) === $connection->id)>{{ $connection->name }} · {{ $connection->network_code }}</option>@endforeach
            </select></label>
            <label>Ad unit<input class="hm-input" name="ad_unit" list="gam-report-unit-options" value="{{ old('ad_unit', $reportBinding?->ad_unit_id) }}" placeholder="Search by name, code or ID" autocomplete="off" maxlength="255" required aria-describedby="gam-report-unit-help"></label>
            <datalist id="gam-report-unit-options"></datalist>
            <small id="gam-report-unit-help" data-unit-feedback aria-live="polite">Select a search result or enter the exact name, code or ID.</small>
            <p class="muted">Reports cover the selected unit only, excluding child units. Currency and timezone come from Google. The first import covers the current open month, starting after any days already imported for this website. Existing financial history is preserved. Ad delivery settings stay as configured.</p>
            <button class="hm-button-primary">{{ $reportBinding ? 'Update reporting connection' : 'Connect reports' }}</button>
        </form>
        <p class="muted">Synchronization checks every five minutes. Today's estimates refresh hourly; completed days refresh every six hours while the financial period is open. Google may take time to prepare a report.</p>
    @endif
</article>
