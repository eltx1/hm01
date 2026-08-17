<article id="serving-control-center" class="workspace-section">
    <div class="workspace-heading">
        <div><p class="eyebrow">Multi-engine serving</p><h2>Serving Control Center</h2><p class="muted">One operational view of the master serving gate, GAM, Header Bidding, Direct JS, renderer ownership, and source-aware financial reporting.</p></div>
        @if(auth()->user()->hasPermission('operations.view'))<a class="section-anchor" href="{{ route('admin.operations.index') }}">Open Operations</a>@endif
    </div>

    @php
        $engineCards = [
            ['key' => 'AD_SERVING', 'title' => 'MASTER AD SERVING', 'state' => $servingOverview['master']['status'], 'health' => $servingOverview['master']['health'], 'detail' => 'Stops every serving engine for this website.'],
            ['key' => 'GAM', 'title' => 'GAM', 'state' => $servingOverview['gam']['status'], 'health' => $servingOverview['gam']['health'], 'detail' => $servingOverview['gam']['connection'] ? $servingOverview['gam']['mode'].' · '.$servingOverview['gam']['connection'] : $servingOverview['gam']['mode'].' · optional/not configured'],
            ['key' => 'PREBID', 'title' => 'PREBID', 'state' => $servingOverview['prebid']['status'], 'health' => $servingOverview['prebid']['health'], 'detail' => 'Configured '.$servingOverview['prebid']['configured_mode'].' · resolved '.$servingOverview['prebid']['resolved_mode'].' · build '.($servingOverview['prebid']['build'] ?: '—')],
            ['key' => 'DIRECT_JS', 'title' => 'DIRECT JS', 'state' => $servingOverview['direct_js']['status'], 'health' => $servingOverview['direct_js']['health'], 'detail' => count((array) $servingOverview['direct_js']['networks']).' network(s) · '.$servingOverview['direct_js']['placements'].' placement(s)'],
        ];
        $trafficGateSiteState = $site->servingSettings?->traffic_gate_state?->value ?? 'INHERIT';
        $trafficGateSitePolicy = $site->servingSettings?->traffic_gate_policy?->value ?? 'INHERIT';
    @endphp

    <div class="health-grid">
        @foreach($engineCards as $engine)
            <div>
                <span class="muted">{{ $engine['title'] }}</span>
                <strong class="metric-small">{{ $engine['state'] }}</strong>
                <x-status-badge :status="$engine['health']" />
                <small>{{ $engine['detail'] }}</small>
                @if(auth()->user()->hasPermission('operations.manage') && !($engine['key'] === 'GAM' && $engine['state'] === 'NOT_CONFIGURED'))
                    <form method="POST" action="{{ route('admin.operations.controls') }}" class="form-stack" style="margin-top:.75rem">
                        @csrf
                        <input type="hidden" name="scope_type" value="SITE">
                        <input type="hidden" name="scope_id" value="{{ $site->id }}">
                        <input type="hidden" name="control_key" value="{{ $engine['key'] }}">
                        <input type="hidden" name="is_disabled" value="{{ $engine['state'] === 'OFF' ? 0 : 1 }}">
                        <input class="hm-input" name="reason" minlength="8" maxlength="2000" placeholder="Required operational reason" required>
                        <input class="hm-input" type="password" name="current_password" autocomplete="current-password" placeholder="Current password" required>
                        <button class="{{ $engine['state'] === 'OFF' ? 'hm-button-secondary' : 'hm-button-danger' }}">{{ $engine['state'] === 'OFF' ? 'Turn ON' : 'Turn OFF' }}</button>
                    </form>
                @endif
            </div>
        @endforeach
    </div>

    <div class="workspace-heading" style="margin-top:1.5rem">
        <div>
            <p class="eyebrow">Client-only soft traffic filter</p>
            <h3>Client Traffic Gate</h3>
            <p class="muted">Task 48 publishes configuration only. No Turnstile script, iframe, browser blocking, Siteverify, Worker validation, visitor Laravel request, or traffic telemetry is executed.</p>
        </div>
        <x-status-badge :status="$trafficGate->readiness->value" />
    </div>
    <div class="detail-grid">
        <div>
            <dl>
                <dt>Effective state</dt><dd>{{ $trafficGate->enabled ? 'ENABLED' : 'DISABLED' }}</dd>
                <dt>Readiness</dt><dd>{{ $trafficGate->readiness->value }}</dd>
                <dt>Effective policy</dt><dd>{{ $trafficGate->policy->value }}</dd>
                <dt>Provider</dt><dd>{{ $trafficGate->provider }}</dd>
                <dt>Fixed gate origin</dt><dd><code>{{ $trafficGate->gateOrigin }}</code></dd>
                <dt>Global timings</dt><dd>{{ $trafficGate->initialWaitMs }} / {{ $trafficGate->maxWaitMs }} / {{ $trafficGate->retryIntervalMs }} ms</dd>
            </dl>
        </div>
        <div>
            @if(auth()->user()->hasPermission('sites.serving.manage'))
                <form method="POST" action="{{ route('admin.sites.traffic-gate', $site) }}" class="form-stack">
                    @csrf
                    <label>Site gate state
                        <select class="hm-input" name="traffic_gate_state">
                            @foreach(\App\Enums\TrafficGateSiteState::cases() as $state)
                                <option value="{{ $state->value }}" @selected($trafficGateSiteState === $state->value)>{{ $state->value }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Site policy
                        <select class="hm-input" name="traffic_gate_policy">
                            @foreach(\App\Enums\TrafficGateSitePolicy::cases() as $policy)
                                <option value="{{ $policy->value }}" @selected($trafficGateSitePolicy === $policy->value)>{{ $policy->value }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Required reason<input class="hm-input" name="reason" minlength="8" maxlength="2000" required></label>
                    <button class="hm-button-secondary">Save Client Traffic Gate override</button>
                </form>
            @else
                <p class="muted">Your role has read-only Client Traffic Gate access.</p>
            @endif
        </div>
    </div>

    <div class="workspace-heading" style="margin-top:1.5rem"><div><p class="eyebrow">Renderer ownership</p><h3>Placement Matrix</h3></div><span class="muted">Production config v{{ $servingOverview['production_config']['version'] ?: '—' }}</span></div>
    <div class="table-scroll"><table class="hm-table"><thead><tr><th>Placement</th><th>GAM</th><th>Prebid</th><th>Direct JS</th><th>Renderer</th><th>Status</th></tr></thead><tbody>
        @forelse($servingOverview['placement_matrix'] as $row)
            <tr><td><strong>{{ $row['name'] }}</strong><small>{{ $row['placement'] }}</small></td><td>{{ $row['gam'] }}</td><td>{{ $row['prebid'] }}</td><td>{{ $row['direct_js'] }}</td><td><code>{{ $row['renderer'] }}</code></td><td><x-status-badge :status="$row['status']" /></td></tr>
        @empty<tr><td colspan="6" class="muted">No production placement configuration has been published yet.</td></tr>@endforelse
    </tbody></table></div>

    <div class="workspace-heading" style="margin-top:1.5rem"><div><p class="eyebrow">Aggregated finance inputs</p><h3>Reporting Health</h3><p class="muted">Provider API/CSV/approved aggregated imports only. Browser impressions are not a finance source.</p></div><x-status-badge :status="$servingOverview['reporting']['status']" /></div>
    <div class="table-scroll"><table class="hm-table"><thead><tr><th>Engine</th><th>Demand source</th><th>Health</th><th>Report source</th><th>Last report</th><th>Last successful import</th></tr></thead><tbody>
        @forelse($servingOverview['reporting']['sources'] as $source)
            <tr><td>{{ $source['engine'] }}</td><td>{{ $source['label'] }}</td><td><x-status-badge :status="$source['status']" /></td><td>{{ $source['report_source'] ?: '—' }}</td><td>{{ $source['last_report_date'] ?: '—' }}</td><td>{{ $source['last_successful_import_at'] ?: '—' }}</td></tr>
        @empty<tr><td colspan="6" class="muted">No active monetization source currently requires financial reporting.</td></tr>@endforelse
    </tbody></table></div>
</article>
