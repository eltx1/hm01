<article id="privacy-readiness" class="workspace-section">
    <div class="workspace-heading">
        <div>
            <p class="eyebrow">Privacy, CMP &amp; consent evidence</p>
            <h2>Privacy Readiness</h2>
            <p>Configuration readiness and live browser evidence are shown separately. Horus does not treat either as legal certification.</p>
        </div>
        <x-status-badge :status="$privacyReadiness['overall']['status']" />
    </div>
    <div class="status-row">
        <span class="pill">{{ $privacyReadiness['overall']['configuration_state'] }}</span>
        <span class="pill">{{ $privacyReadiness['overall']['live_state'] }}</span>
        <span class="pill">Last verified {{ $privacyReadiness['last_verified'] ?: 'never' }}</span>
    </div>

    <div class="health-grid" style="margin-top:1rem">
        @foreach(['configuration' => 'Configuration', 'live' => 'Live Test', 'tcf' => 'TCF', 'gpp' => 'GPP', 'gpc' => 'GPC', 'prebid' => 'Prebid', 'google' => 'Google CMP Evidence', 'providers' => 'Provider Privacy Findings'] as $key => $label)
        <div>
            <span class="muted">{{ $label }}</span>
            <x-status-badge :status="data_get($privacyReadiness, 'sections.'.$key.'.status', 'UNKNOWN')" />
            @if($internal)
            <details><summary>Evidence details</summary><dl>
                @foreach(data_get($privacyReadiness, 'sections.'.$key.'.details', []) as $detailKey => $detailValue)
                    <dt>{{ str($detailKey)->replace('_', ' ')->headline() }}</dt>
                    <dd>{{ is_array($detailValue) ? json_encode($detailValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ($detailValue === null ? '—' : (is_bool($detailValue) ? ($detailValue ? 'Yes' : 'No') : $detailValue)) }}</dd>
                @endforeach
            </dl></details>
            @endif
        </div>
        @endforeach
    </div>

    @if($internal)
        @foreach($privacyReadiness['findings'] as $finding)
            <p class="{{ $finding['status'] === 'BLOCKED' ? 'error' : 'muted' }}"><strong>{{ $finding['code'] }}</strong>: {{ $finding['message'] }}</p>
        @endforeach
        @if(auth()->user()->hasPermission('configs.manage'))
        <section class="detail-grid">
            <form class="form-stack" method="POST" action="{{ route('admin.sites.privacy-diagnostics.run', $site) }}">@csrf
                <h3>Run Live Privacy Test</h3>
                <input type="hidden" name="environment" value="PRODUCTION">
                <p class="muted">Creates a single-use link valid for approximately {{ config('privacy.diagnostic_ttl_minutes', 10) }} minutes.</p>
                <button class="hm-button-primary">Run Live Privacy Test</button>
            </form>
            <form class="form-stack" method="POST" action="{{ route('admin.sites.google-cmp-evidence.update', $site) }}">@csrf @method('PUT')
                <h3>Google CMP operator evidence</h3>
                <input type="hidden" name="environment" value="PRODUCTION">
                <label>CMP name<input class="hm-input" name="cmp_name" value="{{ data_get($privacyReadiness, 'sections.google.details.cmp_name') }}" required></label>
                <label>TCF CMP ID<input class="hm-input" type="number" min="0" name="tcf_cmp_id" value="{{ data_get($privacyReadiness, 'sections.google.details.tcf_cmp_id') }}" required></label>
                <label>Platform<select class="hm-input" name="platform">@foreach(['WEB','APP','CTV','WEB_AND_APP','WEB_APP_CTV'] as $platform)<option @selected(data_get($privacyReadiness, 'sections.google.details.platform') === $platform)>{{ $platform }}</option>@endforeach</select></label>
                <label>Last verification date<input class="hm-input" type="date" name="last_verification_date" value="{{ data_get($privacyReadiness, 'sections.google.details.last_verification_date') }}" required></label>
                <label>Operator status<select class="hm-input" name="operator_verification_status"><option>NOT_VERIFIED</option><option @selected(data_get($privacyReadiness, 'sections.google.details.evidence_status') === 'VERIFIED')>VERIFIED</option></select></label>
                <button class="hm-button-secondary">Record evidence</button>
            </form>
        </section>
        @endif
    @else
        <p class="muted">Horus Media manages provider-internal privacy configuration and verification details.</p>
    @endif
</article>
