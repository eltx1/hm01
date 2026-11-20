<article class="workspace-section" aria-labelledby="installation-check-title">
    <h2 id="installation-check-title">Check a page installation</h2>
    <p>This checks the HTML of a public page on this website for its assigned Horus Loader tag. It does not execute ads or verify a browser impression. Pages that require sign-in, block the checker or insert the tag only through JavaScript need separate browser evidence.</p>
    @if(($result = session('installation_check')) && ($result['site_id'] ?? null) === $site->id)
        <div class="notice" role="status">
            Result: {{ str($result['code'])->replace('_', ' ')->label() }}.
            HTTP: {{ $result['http_status'] ?? 'Unavailable' }}. Checked (UTC): {{ $result['checked_at_utc'] }}.
        </div>
        @php
            // Evidence gaps are not installation failures. Only bounded,
            // allowlisted status codes ever reach this view; no fetched URL,
            // HTML, IP address, token or raw exception is displayed.
            $code = (string) ($result['code'] ?? '');
            $httpStatus = (int) ($result['http_status'] ?? 0);
            $detail = match ($code) {
                'CORRECT_TAG_FOUND' => 'The expected Loader tag and site key appear in this page HTML. Actual script execution and ad delivery still need browser evidence.',
                'WRONG_SITE_KEY', 'MISSING_SITE_KEY' => 'The Loader tag has a different or missing site key. Recopy the assigned snippet from this website, then check the page again.',
                'MULTIPLE_LOADER_TAGS' => 'More than one matching or conflicting Loader tag was found. Remove duplicates and retain only the assigned tag.',
                'LOADER_TAG_NOT_FOUND' => 'The assigned Loader tag was not found in the downloaded HTML. Check the actual public page source; scripts inserted later by JavaScript need separate browser inspection.',
                'HTTP_NOT_SUCCESSFUL' => in_array($httpStatus, [401, 403, 429], true)
                    ? 'The website refused or rate-limited this server-side check. This is not proof the Loader is missing. Verify in your browser without bypassing site protection.'
                    : 'The public page returned a non-success HTTP response, so installation could not be established. Verify the page URL and public availability.',
                'CHECK_TIMEOUT', 'CHECK_TRANSPORT_UNAVAILABLE', 'FETCH_UNAVAILABLE_OR_UNSAFE', 'UNSUPPORTED_ENCODING' => 'The checker could not safely read public HTML. Installation is unknown, not failed; inspect the page in a normal browser.',
                'UNAUTHORIZED_HOST', 'UNSAFE_REDIRECT', 'REDIRECT_LIMIT' => 'The URL or redirect could not be checked safely. Use a public page on this verified website without cross-site or private redirects.',
                'NOT_HTML', 'PAGE_TOO_LARGE', 'UNREADABLE_HTML' => 'The response is not inspectable HTML within the safety limits. Check a smaller public HTML page or verify installation manually in a browser.',
                default => 'This result describes one bounded HTML request only. It is not proof of a live impression or a current ad-serving failure.',
            };
        @endphp
        <p class="muted" data-installation-check-explanation>{{ $detail }}</p>
    @endif
    <form method="POST" action="{{ route($internal ? 'admin.sites.installation-check' : 'publisher.sites.installation-check', $site) }}" class="form-stack">
        @csrf
        <label>Page URL<input class="hm-input" type="url" name="page_url" maxlength="2048" value="https://{{ $site->primary_domain }}/" required autocomplete="off"></label>
        <button class="hm-button-secondary" type="submit" data-submitting-label="Checking…">Check page HTML</button>
    </form>
</article>
