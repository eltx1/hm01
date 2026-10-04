@php($videoBinding = $site->currentGamVideoReportBinding)
<article id="video-reporting" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Independent reporting</p><h2>Video reporting</h2><p class="muted">Optional, off by default. Connect a separate account and Video ad unit independently of the main website report. This does not change ad delivery.</p></div><span class="pill">{{ $videoBinding && $videoBinding->connection?->is_enabled ? 'Enabled' : 'Disabled' }}</span></div>
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
