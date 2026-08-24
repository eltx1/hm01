@extends('layouts.admin')
@section('title', 'Master Ads.txt Records')
@section('heading', 'Master Ads.txt Records')
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Supply Chain & Compliance</p>
        <h2>Platform-wide authorized sellers</h2>
        <p>Manage the complete Horus master ads.txt file as one reviewed document, while every record remains structured, auditable and reversible.</p>
    </div>
    <div class="hero-stat"><strong>{{ $impactedSiteCount }}</strong><span>currently eligible websites</span></div>
</section>

<x-control-plane.workspace-tabs :items="[
    ['label' => 'Control Center', 'href' => route('admin.compliance.supply-chain.overview')],
    ['label' => 'Ads.txt', 'href' => route('admin.compliance.ads-txt.index')],
    ['label' => 'Master Ads.txt Records', 'href' => route('admin.compliance.ads-txt.master.index')],
    ['label' => 'Sellers & schain', 'href' => route('admin.compliance.sellers.index'), 'visible' => auth()->user()->hasPermission('supply_chain.sellers.view')],
]" label="Supply chain compliance sections" />

<x-ads-txt-import-report />

@if(auth()->user()->hasPermission('supply_chain.ads_txt.manage'))
<article class="workspace-section import-panel">
    <div class="workspace-heading"><div><p class="eyebrow">Quick add</p><h2>Add and publish records immediately</h2><p class="muted">Paste the new partner records and submit once. New lines are published immediately, exact duplicates are ignored, and an existing seller identity is updated from its latest pasted line. Invalid lines are skipped without blocking valid additions.</p></div><span class="status-badge status-badge-success">ADMIN DIRECT</span></div>
    <form method="POST" enctype="multipart/form-data" action="{{ route('admin.compliance.ads-txt.master.import') }}" class="form-stack">@csrf
        <label>Paste the records to add<textarea class="hm-input import-textarea" name="ads_txt_records" rows="8" placeholder="exchange.example, seller-123, RESELLER, abcdef123456"></textarea></label>
        <label>Or upload the network file<input class="hm-input" type="file" name="ads_txt_file" accept=".txt,.csv,text/plain,text/csv"></label>
        <div class="notice"><strong>One-step admin action:</strong> no password, preview, duplicate cleanup or second approval is required. Every accepted record becomes active across eligible websites and the change remains audited.</div>
        <button class="hm-button-primary" data-submitting-label="Adding and publishing…">Add and publish records</button>
    </form>
</article>

<article class="workspace-section import-panel">
    <div class="workspace-heading">
        <div><p class="eyebrow">Master file editor</p><h2>View, edit or replace the complete file</h2><p class="muted">Edit up to 5,000 seller records in one place. Removing a line disables that record instead of deleting history. Preview shows the exact diff before anything changes.</p></div>
        <div class="button-row"><button class="hm-button-secondary" type="button" data-copy-target="master-ads-editor">Copy all</button><a class="hm-button-secondary button-link" href="{{ route('admin.compliance.ads-txt.master.export') }}">Download</a></div>
    </div>

    <form method="POST" class="form-stack">@csrf
        <label>Current master ads.txt<textarea id="master-ads-editor" class="hm-input import-textarea" name="master_ads_txt" rows="18" spellcheck="false" placeholder="exchange.example, seller-123, RESELLER, abcdef123456">{{ $masterFile }}</textarea></label>
        <div class="button-row"><button class="hm-button-primary" formaction="{{ route('admin.compliance.ads-txt.master.editor.preview') }}" data-submitting-label="Checking…">Preview changes</button></div>

        @if($masterEditorPreview)
        <section class="metric-grid compliance-metrics" aria-label="Master file change preview">
            <article><p class="eyebrow">Current</p><strong class="metric">{{ $masterEditorPreview['current_count'] }}</strong></article>
            <article><p class="eyebrow">After save</p><strong class="metric">{{ $masterEditorPreview['target_count'] }}</strong></article>
            <article><p class="eyebrow">Added</p><strong class="metric">{{ $masterEditorPreview['added_count'] }}</strong></article>
            <article><p class="eyebrow">Removed</p><strong class="metric">{{ $masterEditorPreview['removed_count'] }}</strong></article>
            <article><p class="eyebrow">Changed</p><strong class="metric">{{ $masterEditorPreview['changed_count'] }}</strong></article>
            <article><p class="eyebrow">Unchanged</p><strong class="metric">{{ $masterEditorPreview['unchanged_count'] }}</strong></article>
            <article><p class="eyebrow">Invalid</p><strong class="metric">{{ $masterEditorPreview['invalid_count'] }}</strong></article>
            <article><p class="eyebrow">Duplicates skipped</p><strong class="metric">{{ $masterEditorPreview['duplicates'] }}</strong></article>
        </section>

        @if($masterEditorPreview['invalid_count'] > 0)
            <div class="notice notice-danger"><strong>Nothing can be applied yet.</strong> Fix every invalid/conflicting row and preview again.</div>
            @foreach(array_slice($masterEditorPreview['invalid'], 0, 50) as $invalid)<div class="finding finding-danger"><strong>Line {{ $invalid['line'] }}</strong><code>{{ $invalid['content'] }}</code><span>{{ $invalid['message'] }}</span></div>@endforeach
        @else
            <div class="detail-grid">
                <div><h3>Added</h3>@forelse(array_slice($masterEditorPreview['added'], 0, 25) as $line)<code class="record-line record-correct">{{ $line }}</code>@empty<p class="muted">No additions.</p>@endforelse</div>
                <div><h3>Removed</h3>@forelse(array_slice($masterEditorPreview['removed'], 0, 25) as $line)<code class="record-line record-missing">{{ $line }}</code>@empty<p class="muted">No removals.</p>@endforelse</div>
            </div>
            @if($masterEditorPreview['changed_count'] > 0)<div><h3>Changed</h3>@foreach(array_slice($masterEditorPreview['changed'], 0, 25) as $change)<div class="finding"><code>{{ $change['before'] }}</code><span>→</span><code>{{ $change['after'] }}</code></div>@endforeach</div>@endif

            @if(auth()->user()->hasPermission('supply_chain.sellers.review'))
            <details class="import-confirmation" open>
                <summary>Apply this exact master-file replacement</summary>
                <p class="muted">The operation is atomic. Existing lines removed from the editor become disabled and stay in the audit history. New/changed lines become reviewed active master records.</p>
                <div class="form-grid">
                    <label>Reason<input class="hm-input" name="reason" minlength="8" maxlength="1000" value="{{ old('reason', 'Update platform master ads.txt file') }}"></label>
                    <label>Current password<input class="hm-input" type="password" name="current_password" autocomplete="current-password"></label>
                </div>
                <label class="check"><input type="checkbox" name="confirm_replace" value="1"> I reviewed this diff and confirm the platform-wide master file replacement.</label>
                <button class="hm-button-primary" formaction="{{ route('admin.compliance.ads-txt.master.editor.apply') }}" data-submitting-label="Applying…">Apply master file</button>
            </details>
            @endif
        @endif
        @endif
    </form>
</article>
@endif

@if(auth()->user()->hasPermission('supply_chain.ads_txt.manage'))
<article class="workspace-section secondary-workflow">
    <p class="eyebrow">Single record</p><h2>Add one platform authorization</h2><p class="muted">New single records start disabled and awaiting review.</p>
    <form method="POST" action="{{ route('admin.compliance.ads-txt.master.store') }}" class="form-stack">@csrf
        <div class="form-grid"><label>Advertising system domain<input class="hm-input" name="advertising_system_domain" required placeholder="exchange.example"></label><label>Publisher / seller account ID<input class="hm-input" name="publisher_account_id" required></label><label>Relationship<select class="hm-input" name="relationship" required><option>DIRECT</option><option>RESELLER</option></select></label><label>Certification authority ID<input class="hm-input" name="certification_authority_id"></label><label>Effective from<input class="hm-input" type="datetime-local" name="effective_from"></label><label>Effective to<input class="hm-input" type="datetime-local" name="effective_to"></label></div>
        <label>Internal notes<textarea class="hm-input" name="internal_notes" maxlength="2000"></textarea></label><button class="hm-button-secondary">Add disabled record</button>
    </form>
</article>
@endif

<article class="workspace-section">
    <p class="eyebrow">Structured registry</p><h2>All master records and history</h2><div class="notice"><strong>Exact current impact:</strong> Active reviewed records are eligible on {{ $impactedSiteCount }} websites, subject to canonical conflict handling.</div>
    <div class="table-wrap"><table><thead><tr><th>Authorization</th><th>Status</th><th>Review</th><th>Effective window</th><th>Remote verification</th><th>Actions</th></tr></thead><tbody>
    @forelse($records as $record)
        <tr><td><code>{{ $record->raw_record }}</code><small class="table-note">Created {{ $record->created_at?->diffForHumans() }} · updated {{ $record->updated_at?->diffForHumans() }}</small></td><td><x-status-badge :status="$record->status" /></td><td><x-status-badge :status="$record->review_status?->value ?? (string) $record->review_status" /><small class="table-note">{{ $record->reviewed_at?->diffForHumans() ?: 'Not reviewed' }}</small></td><td>{{ $record->effective_from?->toDateTimeString() ?: 'Immediate' }}<br>→ {{ $record->effective_to?->toDateTimeString() ?: 'No expiry' }}</td><td><x-status-badge :status="$record->remote_verification_status" /><small class="table-note">{{ $record->remote_error_code ?: ($record->last_verified_at?->diffForHumans() ?: 'Never checked') }}</small></td>
            <td><div class="button-row">@if(auth()->user()->hasPermission('supply_chain.ads_txt.verify'))<form method="POST" action="{{ route('admin.compliance.ads-txt.master.verify', $record) }}">@csrf<button class="hm-button-secondary">Verify</button></form>@endif @if(auth()->user()->hasPermission('supply_chain.sellers.review'))<form method="POST" action="{{ route('admin.compliance.ads-txt.master.review', $record) }}">@csrf<input type="hidden" name="review_status" value="VERIFIED"><button class="hm-button-secondary">Review: Verified</button></form>@endif</div></td>
        </tr>
        @if(auth()->user()->hasPermission('supply_chain.ads_txt.manage'))
        <tr><td colspan="6"><details><summary>Advanced single-record controls</summary>
            <form method="POST" action="{{ route('admin.compliance.ads-txt.master.update', $record) }}" class="form-stack">@csrf @method('PUT')
                <div class="form-grid"><label>Domain<input class="hm-input" name="advertising_system_domain" value="{{ $record->advertising_system_domain }}" required></label><label>Seller ID<input class="hm-input" name="publisher_account_id" value="{{ $record->publisher_account_id }}" required></label><label>Relationship<select class="hm-input" name="relationship"><option @selected($record->relationship==='DIRECT')>DIRECT</option><option @selected($record->relationship==='RESELLER')>RESELLER</option></select></label><label>Authority ID<input class="hm-input" name="certification_authority_id" value="{{ $record->certification_authority_id }}"></label><label>Effective from<input class="hm-input" type="datetime-local" name="effective_from" value="{{ $record->effective_from?->format('Y-m-d\TH:i') }}"></label><label>Effective to<input class="hm-input" type="datetime-local" name="effective_to" value="{{ $record->effective_to?->format('Y-m-d\TH:i') }}"></label></div>
                <label>Internal notes<textarea class="hm-input" name="internal_notes">{{ $record->internal_notes }}</textarea></label><button class="hm-button-secondary">Save and reopen review</button>
            </form>
            @if($record->status === 'ACTIVE')
            <form method="POST" action="{{ route('admin.compliance.ads-txt.master.disable', $record) }}" class="form-stack">@csrf
                <p><strong>This record is currently eligible on {{ $impactedSiteCount }} websites.</strong></p>
                <label>Reason<textarea class="hm-input" name="reason" required minlength="8"></textarea></label><label>Current password<input class="hm-input" type="password" name="current_password" required></label><label>Type <code>DISABLE {{ $impactedSiteCount }} SITES</code><input class="hm-input" name="impact_confirmation" required></label><button class="hm-button-danger">Disable globally</button>
            </form>
            @elseif(auth()->user()->hasPermission('supply_chain.sellers.review'))
            <form method="POST" action="{{ route('admin.compliance.ads-txt.master.enable', $record) }}" class="form-stack">@csrf
                <p><strong>This record will appear on {{ $impactedSiteCount }} eligible websites.</strong></p>
                <label>Reason<textarea class="hm-input" name="reason" required minlength="8"></textarea></label><label>Current password<input class="hm-input" type="password" name="current_password" required></label><label>Type <code>ENABLE {{ $impactedSiteCount }} SITES</code><input class="hm-input" name="impact_confirmation" required></label><button class="hm-button-primary">Enable globally</button>
            </form>
            @endif
        </details></td></tr>
        @endif
    @empty<tr><td colspan="6">No platform master authorizations exist.</td></tr>@endforelse
    </tbody></table></div>
</article>

<article class="workspace-section"><p class="eyebrow">Preview</p><h2>Canonical impact preview</h2><p class="muted">Only the first ten websites are shown.</p>@foreach($previewSites as $site)@php($preview = $previews[$site->id])<details><summary>{{ $site->publisher?->display_name }} · {{ $site->primary_domain }} · {{ $preview['record_count'] }} public lines</summary><pre>{{ $preview['content'] }}</pre>@if(!empty($preview['findings']))<ul>@foreach($preview['findings'] as $finding)<li><strong>{{ $finding['severity'] ?? 'INFO' }}</strong> {{ $finding['code'] ?? '' }} — {{ $finding['message'] ?? '' }}</li>@endforeach</ul>@endif</details>@endforeach</article>

<article class="workspace-section"><p class="eyebrow">Audit history</p><h2>Platform master changes</h2><div class="table-wrap"><table><thead><tr><th>When</th><th>Event</th><th>Actor</th><th>Record</th></tr></thead><tbody>@forelse($auditEvents as $event)<tr><td>{{ $event->created_at?->toDateTimeString() }}</td><td>{{ $event->event }}</td><td>{{ $event->actor_id }}</td><td>{{ $event->auditable_id }}</td></tr>@empty<tr><td colspan="4">No master-record audit events yet.</td></tr>@endforelse</tbody></table></div></article>
@endsection
