<article class="workspace-section" aria-labelledby="installation-check-title">
    <h2 id="installation-check-title">Check a page installation</h2>
    <p>This checks the HTML of a public page on this website for its assigned Horus Loader tag. It does not execute ads or verify a browser impression. Pages that require sign-in, block the checker or insert the tag only through JavaScript need separate browser evidence.</p>
    @if(($result = session('installation_check')) && ($result['site_id'] ?? null) === $site->id)
        <div class="notice" role="status">
            Result: {{ str($result['code'])->replace('_', ' ')->headline() }}.
            HTTP: {{ $result['http_status'] ?? 'Unavailable' }}. Checked (UTC): {{ $result['checked_at_utc'] }}.
        </div>
    @endif
    <form method="POST" action="{{ route($internal ? 'admin.sites.installation-check' : 'publisher.sites.installation-check', $site) }}" class="form-stack">
        @csrf
        <label>Page URL<input class="hm-input" type="url" name="page_url" maxlength="2048" value="https://{{ $site->primary_domain }}/" required autocomplete="off"></label>
        <button class="hm-button-secondary" type="submit" data-submitting-label="Checking…">Check page HTML</button>
    </form>
</article>
