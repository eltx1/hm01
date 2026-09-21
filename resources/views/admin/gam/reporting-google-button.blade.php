@if($googleReady)
    <form class="form-stack" method="POST" action="{{ route('admin.sites.reporting.accounts.start', $site) }}">
        @csrf
        <label>Ad unit name or ID <span class="muted">(optional)</span><input class="hm-input" name="ad_unit" value="{{ old('ad_unit') }}" maxlength="255" placeholder="Enter the unit now, or choose it after connecting" autocomplete="off"></label>
        <small>Enter the unit once and we will connect its reports after Google authorization. If your account has several networks, choose the network containing this unit.</small>
        <button class="hm-button-primary" type="submit" data-submitting-label="Connecting to Google…">Connect with Google</button>
    </form>
    <p class="muted">Choose your Google account and approve access. You can connect more than one account and reuse connected accounts for other websites.</p>
    <small>Google describes its Ad Manager permission as “view and manage”. Horus uses this connection for reports and prevents it from changing ads.</small>
@else
    <div role="status">
        <strong>Google connection is awaiting platform activation</strong>
        <p>Horus Media’s Google connection has not been activated yet. No technical setup or files are needed from you. Once activated, connect here with your Google account.</p>
        <p class="muted">Existing connected accounts and CSV reports remain available.</p>
    </div>
@endif
