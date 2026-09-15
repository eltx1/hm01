@extends('layouts.admin')
@section('title', 'Publisher Affiliates')
@section('heading', 'Publisher Affiliates')
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Publisher growth</p>
        <h2>Referral commissions tied to finalized Publisher earnings</h2>
        <p>Commission basis is the referred Publisher's finalized <strong>publisher earnings</strong>, never gross revenue. Historical ledger rows snapshot the basis and rate and are not rewritten by later setting changes.</p>
    </div>
    <span class="pill">Default {{ number_format($defaultRateBp / 100, 2) }}%</span>
</section>

<article>
    <p class="eyebrow">Global default</p>
    <h2>Default commission rate</h2>
    <form method="POST" action="{{ route('admin.publisher-affiliates.settings.update') }}" class="form-stack">
        @csrf
        @method('PUT')
        <label for="affiliate-default-rate">Commission percent</label>
        <input id="affiliate-default-rate" class="hm-input" type="number" name="commission_percent" min="0" max="100" step="0.01" value="{{ old('commission_percent', number_format($defaultRateBp / 100, 2, '.', '')) }}" required>
        <p class="field-help">Used for future finalized periods unless the referrer has an individual override.</p>
        <button class="hm-button-primary" type="submit">Save default rate</button>
    </form>
</article>

<section class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Publisher controls</p><h2>Referral attribution, codes, and rate overrides</h2></div></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Publisher</th><th>Referral code</th><th>Referrer</th><th>Referrals</th><th>Future rate</th><th>Manage</th></tr></thead>
            <tbody>
            @foreach($publishers as $publisher)
                <tr>
                    <td><strong>{{ $publisher->display_name }}</strong></td>
                    <td><code>{{ $publisher->referral_code }}</code></td>
                    <td>{{ $publisher->referrer?->display_name ?? 'Direct / none' }}</td>
                    <td>{{ number_format($publisher->referrals_count) }}</td>
                    <td>{{ $publisher->affiliate_commission_override_bp === null ? 'Default' : number_format($publisher->affiliate_commission_override_bp / 100, 2).'%' }}</td>
                    <td>
                        <details>
                            <summary>Manage</summary>
                            <form method="POST" action="{{ route('admin.publisher-affiliates.publishers.update', $publisher) }}" class="form-stack" style="min-width:18rem">
                                @csrf
                                @method('PUT')
                                <label>Referral code</label>
                                <input class="hm-input" name="referral_code" value="{{ $publisher->referral_code }}" minlength="4" maxlength="32" pattern="[A-Za-z0-9_-]+" required>
                                <p class="field-help">Changing the code invalidates the Publisher's old referral link. Finalized historical commissions are unaffected.</p>
                                <label>Commission override %</label>
                                <input class="hm-input" type="number" name="commission_percent" min="0" max="100" step="0.01" value="{{ $publisher->affiliate_commission_override_bp === null ? '' : number_format($publisher->affiliate_commission_override_bp / 100, 2, '.', '') }}" placeholder="Blank = global default">
                                <label>Referred by</label>
                                <select class="hm-input" name="referred_by_publisher_id">
                                    <option value="">Direct / none</option>
                                    @foreach($publisherOptions as $option)
                                        @if($option->id !== $publisher->id)
                                            <option value="{{ $option->id }}" @selected($publisher->referred_by_publisher_id === $option->id)>{{ $option->display_name }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                <button class="hm-button-primary" type="submit">Save</button>
                            </form>
                        </details>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    {{ $publishers->links() }}
</section>

<section class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Immutable period ledger</p><h2>Affiliate commission records</h2></div></div>
    @if($commissions->count())
        <div class="table-wrap">
            <table>
                <thead><tr><th>Period</th><th>Referrer</th><th>Referred Publisher</th><th>Source statement</th><th>Basis</th><th>Rate</th><th>Commission</th><th>Status</th></tr></thead>
                <tbody>
                @foreach($commissions as $commission)
                    <tr>
                        <td>{{ $commission->period?->period_key ?? '—' }}</td>
                        <td>{{ $commission->referrer?->display_name ?? '—' }}</td>
                        <td>{{ $commission->referredPublisher?->display_name ?? '—' }}</td>
                        <td><code>{{ $commission->sourceStatement?->statement_number ?? '—' }}</code></td>
                        <td class="money">{{ $commission->currency }} {{ \App\Support\Money::formatMinor((int) $commission->basis_minor) }}</td>
                        <td>{{ number_format($commission->commission_rate_bp / 100, 2) }}%</td>
                        <td class="money">{{ $commission->currency }} {{ \App\Support\Money::formatMinor((int) $commission->commission_minor) }}</td>
                        <td><x-status-badge :status="$commission->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $commissions->links() }}
    @else
        <x-empty-state title="No affiliate commission records" description="Ledger rows are created when a financial period closes and a referred Publisher has finalized Publisher earnings." />
    @endif
</section>
@endsection
