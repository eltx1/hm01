@php
    $siteQualityRuns = \App\Models\SiteQualityReviewRun::query()
        ->where('site_id', $site->id)
        ->latest()
        ->limit(10)
        ->get();
    $siteQualityRun = $siteQualityRuns->first();
    $thothSettings = \App\Models\ThothSetting::current();
@endphp

<article id="quality-review" class="workspace-section">
    <div class="workspace-heading">
        <div>
            <p class="eyebrow">THOTH · advisory only</p>
            <h2>Quality Review</h2>
            <p class="muted">THOTH runs automatically after website submission. Its result never approves, rejects, activates, or blocks the website. The human Admin decision remains authoritative.</p>
        </div>
        <x-status-badge :status="$siteQualityRun?->status ?? ($thothSettings->enabled ? 'WAITING' : 'AI DISABLED')" />
    </div>

    @if(!$siteQualityRun)
        <p>No THOTH website advisory has been recorded yet. Human review is available normally.</p>
    @elseif(in_array($siteQualityRun->status, ['QUEUED', 'RUNNING'], true))
        <div class="notice" role="status">
            <strong>THOTH is reviewing this website in the background.</strong>
            <p>Website registration and human review remain fully available while the AI task runs.</p>
        </div>
    @elseif($siteQualityRun->status === 'FAILED')
        <div class="notice error" role="status">
            <strong>THOTH advisory failed — human review is unaffected.</strong>
            <p>{{ $siteQualityRun->error_message ?: 'THOTH could not complete this advisory.' }}</p>
            @if($siteQualityRun->error_code)<small>Error code: {{ $siteQualityRun->error_code }}</small>@endif
        </div>
    @elseif($siteQualityRun->status === 'COMPLETED')
        @php($result = $siteQualityRun->result ?? [])
        <div class="health-grid">
            <div><span class="muted">Recommendation</span><strong>{{ $result['recommended_decision'] ?? 'UNKNOWN' }}</strong></div>
            <div><span class="muted">Risk</span><strong>{{ $result['risk_level'] ?? 'UNKNOWN' }}</strong></div>
            <div><span class="muted">Confidence</span><strong>{{ isset($result['confidence']) ? $result['confidence'].'%' : 'N/A' }}</strong></div>
            <div><span class="muted">Evidence pages</span><strong>{{ count($siteQualityRun->evidence_snapshot['website_evidence'] ?? []) }}</strong></div>
        </div>

        <h3>THOTH summary</h3>
        <p>{{ $result['summary'] ?? 'No summary was returned.' }}</p>

        @if($result['findings'] ?? [])
            <h3>Findings</h3>
            @foreach($result['findings'] as $finding)
                <div class="compact-row">
                    <div>
                        <strong>{{ $finding['severity'] ?? 'INFO' }} · {{ $finding['code'] ?? 'FINDING' }}</strong>
                        <p>{{ $finding['explanation'] ?? '' }}</p>
                        @if($finding['evidence'] ?? null)<small>Evidence: {{ $finding['evidence'] }}</small>@endif
                    </div>
                </div>
            @endforeach
        @endif

        @if($result['positive_signals'] ?? [])
            <p><strong>Positive signals:</strong> {{ implode(' · ', $result['positive_signals']) }}</p>
        @endif
        @if($result['concerns'] ?? [])
            <p><strong>Concerns:</strong> {{ implode(' · ', $result['concerns']) }}</p>
        @endif
        @if($result['recommended_admin_checks'] ?? [])
            <p><strong>Recommended Admin checks:</strong> {{ implode(' · ', $result['recommended_admin_checks']) }}</p>
        @endif
        @if($result['limitations'] ?? [])
            <p class="muted"><strong>Limitations:</strong> {{ implode(' · ', $result['limitations']) }}</p>
        @endif
    @endif

    <div class="compact-row">
        <div>
            <strong>AI execution state</strong>
            <p>{{ $thothSettings->enabled ? 'THOTH is enabled.' : 'THOTH is currently disabled in AI Control Center.' }} A disabled or unavailable provider never blocks website review.</p>
        </div>
        @if($siteQualityRun?->provider)<span class="muted">{{ $siteQualityRun->provider }} · {{ $siteQualityRun->model }}</span>@endif
    </div>

    @if($site->status === \App\Enums\SiteStatus::PendingReview && auth()->user()->hasPermission('sites.review'))
        <form method="POST" action="{{ route('admin.sites.quality-review', $site) }}" class="inline-form safe-submit">
            @csrf
            <button class="hm-button-secondary">Run THOTH again</button>
        </form>
    @endif

    @if($siteQualityRuns->count() > 1)
        <details>
            <summary>Advisory history</summary>
            @foreach($siteQualityRuns as $run)
                <div class="compact-row">
                    <div><strong>{{ $run->status }} · {{ $run->trigger }}</strong><p>{{ $run->created_at }}{{ $run->error_code ? ' · '.$run->error_code : '' }}</p></div>
                    <span class="muted">{{ $run->result['recommended_decision'] ?? $run->error_message ?? '' }}</span>
                </div>
            @endforeach
        </details>
    @endif
</article>
