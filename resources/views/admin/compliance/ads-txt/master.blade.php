@extends('layouts.admin')
@section('title', 'Master Ads.txt Records')
@section('heading', 'Master Ads.txt Records')
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Supply Chain & Compliance</p>
        <h2>Platform-wide authorized sellers</h2>
        <p>Reviewed platform master authorizations are eligible for every approved or active Horus-managed website. They never override conflicting Bidder, Demand, or Horus seller identity records.</p>
    </div>
    <div class="hero-stat"><strong>{{ $impactedSiteCount }}</strong><span>currently eligible websites</span></div>
</section>

<x-control-plane.workspace-tabs :items="[
    ['label' => 'Control Center', 'href' => route('admin.compliance.supply-chain.overview')],
    ['label' => 'Ads.txt', 'href' => route('admin.compliance.ads-txt.index')],
    ['label' => 'Master Ads.txt Records', 'href' => route('admin.compliance.ads-txt.master.index')],
    ['label' => 'Sellers & schain', 'href' => route('admin.compliance.sellers.index'), 'visible' => auth()->user()->hasPermission('supply_chain.sellers.view')],
]" label="Supply chain compliance sections" />

@if(auth()->user()->hasPermission('supply_chain.ads_txt.manage'))
<article class="workspace-section">
    <p class="eyebrow">Create safely</p><h2>Add platform master authorization</h2>
    <p class="muted">New records start DISABLED and REVIEW_REQUIRED. Creating a row never publishes it.</p>
    <form method="POST" action="{{ route('admin.compliance.ads-txt.master.store') }}" class="form-stack">@csrf
        <div class="form-grid">
            <label>Advertising system domain<input class="hm-input" name="advertising_system_domain" required placeholder="exchange.example"></label>
            <label>Publisher / seller account ID<input class="hm-input" name="publisher_account_id" required></label>
            <label>Relationship<select class="hm-input" name="relationship" required><option>DIRECT</option><option>RESELLER</option></select></label>
            <label>Certification authority ID<input class="hm-input" name="certification_authority_id"></label>
            <label>Effective from<input class="hm-input" type="datetime-local" name="effective_from"></label>
            <label>Effective to<input class="hm-input" type="datetime-local" name="effective_to"></label>
        </div>
        <label>Internal notes<textarea class="hm-input" name="internal_notes" maxlength="2000"></textarea></label>
        <button class="hm-button-primary">Add disabled master record</button>
    </form>
</article>
@endif

<article class="workspace-section">
    <p class="eyebrow">Canonical source</p><h2>Reviewed master records</h2>
    <div class="notice"><strong>Exact current impact:</strong> This record will appear on {{ $impactedSiteCount }} eligible websites when enabled, subject to canonical conflict handling.</div>
    <div class="table-wrap"><table><thead><tr><th>Authorization</th><th>Status</th><th>Review</th><th>Effective window</th><th>Remote verification</th><th>Actions</th></tr></thead><tbody>
    @forelse($records as $record)
        <tr>
            <td><code>{{ $record->raw_record }}</code><small class="table-note">Created {{ $record->created_at?->diffForHumans() }} · updated {{ $record->updated_at?->diffForHumans() }}</small></td>
            <td><x-status-badge :status="$record->status" /></td>
            <td><x-status-badge :status="$record->review_status?->value ?? (string) $record->review_status" /><small class="table-note">{{ $record->reviewed_at?->diffForHumans() ?: 'Not reviewed' }}</small></td>
            <td>{{ $record->effective_from?->toDateTimeString() ?: 'Immediate' }}<br>→ {{ $record->effective_to?->toDateTimeString() ?: 'No expiry' }}</td>
            <td><x-status-badge :status="$record->remote_verification_status" /><small class="table-note">{{ $record->remote_error_code ?: ($record->last_verified_at?->diffForHumans() ?: 'Never checked') }}</small></td>
            <td>
                <div class="button-row">
                    @if(auth()->user()->hasPermission('supply_chain.ads_txt.verify'))
                    <form method="POST" action="{{ route('admin.compliance.ads-txt.master.verify', $record) }}">@csrf<button class="hm-button-secondary">Verify</button></form>
                    @endif
                    @if(auth()->user()->hasPermission('supply_chain.sellers.review'))
                    <form method="POST" action="{{ route('admin.compliance.ads-txt.master.review', $record) }}">@csrf<input type="hidden" name="review_status" value="VERIFIED"><button class="hm-button-secondary">Review: Verified</button></form>
                    @endif
                </div>
                @if($record->status === 'ACTIVE' && auth()->user()->hasPermission('supply_chain.ads_txt.manage'))
                    <details class="managed-record"><summary>Disable globally</summary>
                        <form method="POST" action="{{ route('admin.compliance.ads-txt.master.disable', $record) }}" class="form-stack">@csrf
                            <p><strong>This record is currently eligible on {{ $impactedSiteCount }} websites.</strong></p>
                            <label>Reason<textarea class="hm-input" name="reason" required minlength="8" maxlength="1000"></textarea></label>
                            <label>Current password<input class="hm-input" type="password" name="current_password" required autocomplete="current-password"></label>
                            <label>Type <code>DISABLE {{ $impactedSiteCount }} SITES</code><input class="hm-input" name="impact_confirmation" required autocomplete="off"></label>
                            <button class="hm-button-danger">Disable master record</button>
                        </form>
                    </details>
                @elseif($record->status !== 'ACTIVE' && auth()->user()->hasPermission('supply_chain.ads_txt.manage') && auth()->user()->hasPermission('supply_chain.sellers.review'))
                    <details class="managed-record"><summary>Enable globally</summary>
                        <form method="POST" action="{{ route('admin.compliance.ads-txt.master.enable', $record) }}" class="form-stack">@csrf
                            <p><strong>This record will appear on {{ $impactedSiteCount }} eligible websites.</strong></p>
                            <label>Reason<textarea class="hm-input" name="reason" required minlength="8" maxlength="1000"></textarea></label>
                            <label>Current password<input class="hm-input" type="password" name="current_password" required autocomplete="current-password"></label>
                            <label>Type <code>ENABLE {{ $impactedSiteCount }} SITES</code><input class="hm-input" name="impact_confirmation" required autocomplete="off"></label>
                            <button class="hm-button-primary">Enable master record</button>
                        </form>
                    </details>
                @endif
            </td>
        </tr>
        @if(auth()->user()->hasPermission('supply_chain.ads_txt.manage'))
        <tr><td colspan="6">
            <details><summary>Edit master record</summary>
                <form method="POST" action="{{ route('admin.compliance.ads-txt.master.update', $record) }}" class="form-stack">@csrf @method('PUT')
                    <div class="form-grid">
                        <label>Domain<input class="hm-input" name="advertising_system_domain" value="{{ $record->advertising_system_domain }}" required></label>
                        <label>Seller ID<input class="hm-input" name="publisher_account_id" value="{{ $record->publisher_account_id }}" required></label>
                        <label>Relationship<select class="hm-input" name="relationship"><option @selected($record->relationship==='DIRECT')>DIRECT</option><option @selected($record->relationship==='RESELLER')>RESELLER</option></select></label>
                        <label>Authority ID<input class="hm-input" name="certification_authority_id" value="{{ $record->certification_authority_id }}"></label>
                        <label>Effective from<input class="hm-input" type="datetime-local" name="effective_from" value="{{ $record->effective_from?->format('Y-m-d\TH:i') }}"></label>
                        <label>Effective to<input class="hm-input" type="datetime-local" name="effective_to" value="{{ $record->effective_to?->format('Y-m-d\TH:i') }}"></label>
                    </div>
                    <label>Internal notes<textarea class="hm-input" name="internal_notes">{{ $record->internal_notes }}</textarea></label>
                    <p class="muted">Editing always disables the record and reopens review.</p>
                    <button class="hm-button-secondary">Save and reopen review</button>
                </form>
            </details>
        </td></tr>
        @endif
    @empty<tr><td colspan="6">No platform master authorizations exist.</td></tr>@endforelse
    </tbody></table></div>
</article>

<article class="workspace-section">
    <p class="eyebrow">Preview</p><h2>Canonical impact preview</h2>
    <p class="muted">Preview shows the current final canonical composition, including provenance-aware collapse/conflict handling. Only the first ten websites are shown here.</p>
    @foreach($previewSites as $site)
        @php($preview = $previews[$site->id])
        <details><summary>{{ $site->publisher?->display_name }} · {{ $site->primary_domain }} · {{ $preview['record_count'] }} public lines</summary>
            <pre>{{ $preview['content'] }}</pre>
            @if(!empty($preview['findings']))<ul>@foreach($preview['findings'] as $finding)<li><strong>{{ $finding['severity'] ?? 'INFO' }}</strong> {{ $finding['code'] ?? '' }} — {{ $finding['message'] ?? '' }}</li>@endforeach</ul>@endif
        </details>
    @endforeach
</article>

<article class="workspace-section">
    <p class="eyebrow">Audit history</p><h2>Platform master changes</h2>
    <div class="table-wrap"><table><thead><tr><th>When</th><th>Event</th><th>Actor</th><th>Record</th></tr></thead><tbody>
    @forelse($auditEvents as $event)<tr><td>{{ $event->created_at?->toDateTimeString() }}</td><td>{{ $event->event }}</td><td>{{ $event->actor_id }}</td><td>{{ $event->auditable_id }}</td></tr>
    @empty<tr><td colspan="4">No master-record audit events yet.</td></tr>@endforelse
    </tbody></table></div>
</article>
@endsection
