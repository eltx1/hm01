@extends('layouts.admin')
@section('title', $site->display_name.' · Prebid Ads.txt')
@section('heading', 'Prebid Ads.txt · '.$site->display_name)
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Authorized seller governance</p>
        <h2>{{ $site->primary_domain }}</h2>
        <p>Bidder authorized-seller records feed the same canonical ads.txt engine as Demand Accounts and the Horus seller identity.</p>
    </div>
    <div class="status-row">
        <span class="pill">{{ $accounts->count() }} mapped bidder accounts</span>
        <span class="pill">{{ $canonical['record_count'] }} canonical lines</span>
        @if($readiness['required_missing'] > 0)<span class="pill error">ACTION REQUIRED · {{ $readiness['required_missing'] }} missing</span>@endif
        @if($readiness['unknown'] > 0)<span class="pill">UNKNOWN · {{ $readiness['unknown'] }}</span>@endif
    </div>
</section>

@forelse($accounts as $account)
<section class="detail-grid" style="margin-top:1rem">
    <article>
        <p class="eyebrow">{{ $account->bidder?->display_name ?? 'Prebid bidder' }}</p>
        <h3>{{ $account->name }}</h3>
        <p><strong>Requirement:</strong> {{ $account->ads_txt_requirement?->value ?? 'UNKNOWN' }}</p>
        <p class="muted">UNKNOWN remains explicit until reviewed. No provider policy is inferred from the adapter.</p>
        <form class="form-stack" method="POST" action="{{ route('admin.prebid.ads-txt.requirement', [$site, $account]) }}">@csrf @method('PUT')
            <label>Ads.txt requirement
                <select class="hm-input" name="ads_txt_requirement">
                    @foreach($requirements as $requirement)<option value="{{ $requirement->value }}" @selected(($account->ads_txt_requirement?->value ?? 'UNKNOWN') === $requirement->value)>{{ $requirement->value }}</option>@endforeach
                </select>
            </label>
            <label>Evidence URL<input class="hm-input" type="url" name="ads_txt_evidence_url" value="{{ $account->ads_txt_evidence_url }}" placeholder="https://provider.example/docs"></label>
            <label>Last verified<input class="hm-input" type="date" name="ads_txt_requirement_verified_at" value="{{ $account->ads_txt_requirement_verified_at?->format('Y-m-d') }}"></label>
            <button class="hm-button-secondary">Save requirement evidence</button>
        </form>
        <p class="muted" style="margin-top:1rem"><strong>Eligible Sites:</strong>
            {{ $account->siteMappings->where('enabled', true)->filter(fn($mapping) => $mapping->site)->map(fn($mapping) => $mapping->site->primary_domain)->implode(', ') ?: 'None' }}
        </p>
    </article>

    <article>
        <p class="eyebrow">Authorized seller</p>
        <h3>Add record</h3>
        <form class="form-stack" method="POST" action="{{ route('admin.prebid.ads-txt.records.store', [$site, $account]) }}">@csrf
            <label>Scope<select class="hm-input" name="scope"><option value="GLOBAL">Account global — mapped Sites only</option><option value="SITE">This Site only</option></select></label>
            <label>Advertising system domain<input class="hm-input" name="advertising_system_domain" required placeholder="exchange.example"></label>
            <label>Seller / publisher account ID<input class="hm-input" name="publisher_account_id" required></label>
            <label>Relationship<select class="hm-input" name="relationship"><option>DIRECT</option><option>RESELLER</option></select></label>
            <label>Certification authority ID<input class="hm-input" name="certification_authority_id"></label>
            <button class="hm-button-primary">Add record for review</button>
        </form>
    </article>
</section>

<section style="margin-top:1rem">
    <h3>{{ $account->name }} records</h3>
    @forelse($account->adsTxtRecords as $record)
    <div class="domain-card" style="margin-top:.75rem">
        <div class="status-row">
            <strong>{{ $record->raw_record }}</strong>
            <span class="pill">{{ $record->site_id ? 'SITE' : 'GLOBAL' }}</span>
            <span class="pill">{{ $record->relationship }}</span>
            <span class="pill">{{ $record->status }}</span>
            <span class="pill">{{ $record->review_status?->value }}</span>
            <span class="pill">sellers.json {{ $record->remote_verification_status?->value }}</span>
        </div>
        @if($record->remote_error_code)<p class="error">Remote evidence: {{ $record->remote_error_code }}</p>@endif
        <form class="form-stack" method="POST" action="{{ route('admin.prebid.ads-txt.records.update', [$site, $record]) }}">@csrf @method('PUT')
            <label>Scope<select class="hm-input" name="scope"><option value="GLOBAL" @selected(!$record->site_id)>Account global</option><option value="SITE" @selected($record->site_id)>This Site</option></select></label>
            <label>Advertising system domain<input class="hm-input" name="advertising_system_domain" value="{{ $record->advertising_system_domain }}" required></label>
            <label>Seller / publisher account ID<input class="hm-input" name="publisher_account_id" value="{{ $record->publisher_account_id }}" required></label>
            <label>Relationship<select class="hm-input" name="relationship"><option @selected($record->relationship === 'DIRECT')>DIRECT</option><option @selected($record->relationship === 'RESELLER')>RESELLER</option></select></label>
            <label>Certification authority ID<input class="hm-input" name="certification_authority_id" value="{{ $record->certification_authority_id }}"></label>
            <button class="hm-button-secondary">Edit & reopen review</button>
        </form>
        <div class="status-row" style="margin-top:.75rem">
            <form method="POST" action="{{ route('admin.prebid.ads-txt.records.review', [$site, $record]) }}">@csrf
                <input type="hidden" name="review_status" value="VERIFIED"><button class="hm-button-secondary">Review VERIFIED</button>
            </form>
            <form method="POST" action="{{ route('admin.prebid.ads-txt.records.verify-remote', [$site, $record]) }}">@csrf<button class="hm-button-secondary">Verify sellers.json</button></form>
            @if($record->status === 'ACTIVE')<form method="POST" action="{{ route('admin.prebid.ads-txt.records.disable', [$site, $record]) }}">@csrf<button class="hm-button-secondary">Disable</button></form>@endif
        </div>
    </div>
    @empty<p class="muted">No bidder authorized-seller records yet.</p>@endforelse
</section>
@empty
<section class="empty-state"><h3>No bidder accounts mapped to this Site</h3><p>Assign a Prebid bidder account before creating site-scoped or account-global ads.txt records.</p></section>
@endforelse

<section style="margin-top:1rem">
    <p class="eyebrow">Canonical Ads.txt Compliance</p>
    <h3>Current composed lines</h3>
    <pre style="white-space:pre-wrap">{{ $canonical['content'] }}</pre>
    @foreach($canonical['findings'] as $finding)<p class="{{ ($finding['severity'] ?? '') === 'ERROR' ? 'error' : 'muted' }}">{{ $finding['code'] ?? 'FINDING' }} · {{ $finding['message'] ?? '' }}</p>@endforeach
</section>
@endsection
