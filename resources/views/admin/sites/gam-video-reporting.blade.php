@php($videoBinding = $site->currentGamVideoReportBinding)
<article id="video-reporting" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Independent reporting</p><h2>Video reporting</h2><p class="muted">Optional, off by default. Connect a separate account and Video ad unit independently of the main website report. This does not change ad delivery.</p></div><span class="pill">{{ $videoBinding && $videoBinding->connection?->is_enabled ? 'Enabled' : 'Disabled' }}</span></div>
    @if($videoTodayReport ?? null)
        <section id="video-today-report" aria-labelledby="video-today-report-heading">
            <div class="workspace-heading"><div><p class="eyebrow">{{ $site->primary_domain }} · Estimated Video performance</p><h3 id="video-today-report-heading">Video Today so far</h3><p class="muted">{{ $videoTodayReport['date'] }} · {{ $videoTodayReport['timezone'] }} · {{ $videoTodayReport['currency'] }}</p></div><span class="pill">Estimated</span></div>
            @if($videoTodayReport['available'])
                <div class="metric-grid">
                    @foreach([
                        ['Video impressions', \App\Services\Reporting\PerformanceMetrics::display('impressions', $videoTodayReport['impressions'])],
                        ['Video eCPM (gross)', \App\Services\Reporting\PerformanceMetrics::display('ecpm_minor', $videoTodayReport['ecpm_minor']).' '.$videoTodayReport['currency']],
                        ['Video Unfilled (ad unit, all sites)', \App\Services\Reporting\PerformanceMetrics::display('unfilled_impressions', $videoTodayReport['unfilled_impressions'])],
                        ['Estimated Video gross revenue', \App\Support\Money::formatMinor($videoTodayReport['gross_revenue_minor']).' '.$videoTodayReport['currency']],
                        ['Estimated Video publisher earnings', \App\Support\Money::formatMinor($videoTodayReport['publisher_earnings_minor']).' '.$videoTodayReport['currency']],
                        ['Estimated Video Horus margin', \App\Support\Money::formatMinor($videoTodayReport['horus_earnings_minor']).' '.$videoTodayReport['currency']],
                    ] as [$label, $value])
                        <article><p class="eyebrow">{{ $label }}</p><strong class="metric">{{ $value }}</strong></article>
                    @endforeach
                </div>
                @if($videoTodayReport['metric_basis_incomplete'])<p class="muted">Video performance metrics are unavailable because this report uses legacy website GAM counters.</p>@endif
                <p>Snapshot imported: {{ $videoTodayReport['updated_at'] }} · {{ $videoTodayReport['timezone'] }}</p>
            @elseif(! $videoTodayReport['scope_current'])
                <p role="status">Today's Video report is waiting for the current website reporting scope. Estimates for a previous hostname or scope are not shown.</p>
            @else
                <p role="status">Today's Video report has not arrived yet. Missing reports do not mean zero revenue.</p>
            @endif
            <p class="muted">{{ $videoTodayReport['refresh_enabled'] ? 'Updated automatically every hour. Google data may be delayed.' : 'Automatic refresh is paused for this Video reporting connection.' }} These are the latest imported Video estimates, separate from main performance and finalized payout amounts.</p>
        </section>
    @endif
    @if($videoBinding)
        <p><strong>{{ $videoBinding->gamConnection?->name }} · {{ $videoBinding->ad_unit_name }}</strong></p>
        <p>Network {{ $videoBinding->network_code }} · Unit {{ $videoBinding->ad_unit_id }} · From {{ $videoBinding->starts_on->toDateString() }}</p>
        <p>Last successful import: {{ $videoBinding->connection?->last_successful_import_at ?? 'Waiting for first synchronization' }}</p>
        @if($videoBinding->connection?->last_error)<p role="alert">Latest Video refresh failed: {{ $videoBinding->connection->last_error }}</p>@endif
        <form method="POST" action="{{ route('admin.sites.reporting.video.destroy', $site) }}">@csrf @method('DELETE')<button class="hm-button-secondary">Disable Video reporting</button></form>
    @endif
    @if($reportingGamConnections->isNotEmpty())
    <form method="POST" action="{{ route('admin.sites.reporting.video.store', $site) }}" class="form-stack" data-gam-report-binding data-units-url="{{ route('admin.sites.reporting.gam.units', $site) }}">
        @csrf
        <label>Video Ad Manager account<select class="hm-input" name="video_gam_connection_id" required><option value="">Choose an account</option>@foreach($reportingGamConnections as $connection)<option value="{{ $connection->id }}" @selected(old('video_gam_connection_id', $videoBinding?->gam_connection_id) === $connection->id)>{{ $connection->name }} · {{ $connection->network_code }}</option>@endforeach</select></label>
        @error('video_gam_connection_id')<p role="alert">{{ $message }}</p>@enderror
        <label>Video ad unit<input class="hm-input" name="video_ad_unit" list="gam-video-unit-options" value="{{ old('video_ad_unit', $videoBinding?->ad_unit_id) }}" placeholder="Search by name, code or ID" autocomplete="off" maxlength="255" required aria-describedby="gam-video-unit-help"></label>
        <datalist id="gam-video-unit-options"></datalist><small id="gam-video-unit-help" data-unit-feedback aria-live="polite">Select a search result or enter the exact name, code or ID.</small>
        @error('video_ad_unit')<p role="alert">{{ $message }}</p>@enderror
        <p class="muted">Video revenue and impressions use Ad Exchange for this exact hostname and selected unit, excluding child units. Original Unfilled covers the selected ad unit across all sites and is not added to main Unfilled. Video earnings use the dated publisher revenue share and are included in financial totals. Connecting starts forward only; historical financial corrections require separate review.</p>
        <button class="hm-button-primary">{{ $videoBinding ? 'Update Video connection' : 'Enable Video reporting' }}</button>
    </form>
    @else<p class="muted">Connect an Ad Manager account above to enable optional Video reports.</p>@endif
</article>
