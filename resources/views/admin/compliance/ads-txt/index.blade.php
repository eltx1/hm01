@extends('layouts.admin')
@section('title', 'Ads.txt compliance')
@section('heading', 'Ads.txt Compliance Center')
@section('content')
<section class="hero">
    <div><p class="eyebrow">Supply Chain & Compliance</p><h2>Network ads.txt</h2><p>Computed compliance across every publisher website. Status is derived from the canonical control-plane records and the latest safely fetched public file.</p></div>
</section>

<x-control-plane.workspace-tabs :items="[
    ['label' => 'Ads.txt', 'href' => route('admin.compliance.ads-txt.index')],
    ['label' => 'Master Ads.txt Records', 'href' => route('admin.compliance.ads-txt.master.index'), 'visible' => auth()->user()->hasPermission('supply_chain.ads_txt.view')],
    ['label' => 'Sellers & schain', 'href' => route('admin.compliance.sellers.index'), 'visible' => auth()->user()->hasPermission('supply_chain.sellers.view')],
]" label="Supply chain compliance sections" />

<x-ads-txt-import-report />

@if(auth()->user()->hasPermission('supply_chain.ads_txt.manage'))
<article class="workspace-section import-panel">
    <div class="workspace-heading"><div><p class="eyebrow">Demand bulk import</p><h2>Import all records from a demand partner</h2><p class="muted">Paste or upload the partner's full ads.txt file once. Valid records become active for the selected demand-account scope; duplicates are skipped.</p></div><span class="status-badge status-badge-success">UP TO 5,000 LINES</span></div>
    <form method="POST" enctype="multipart/form-data" action="{{ route('admin.compliance.ads-txt.bulk-import') }}" class="form-stack">@csrf
        <div class="form-grid">
            <label>Demand account<select class="hm-input" name="demand_account_id" required><option value="">Select account</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->network?->name }} · {{ $account->name }}</option>@endforeach</select></label>
            <label>Scope<select class="hm-input" name="site_id"><option value="">Every website mapped to this account</option>@foreach($sites as $site)<option value="{{ $site->id }}">{{ $site->publisher->display_name }} · {{ $site->primary_domain }}</option>@endforeach</select></label>
        </div>
        <label>Paste ads.txt records<textarea class="hm-input import-textarea" name="ads_txt_records" rows="10" placeholder="exchange.example, seller-123, DIRECT, abcdef123456"></textarea></label>
        <label>Or upload the partner file<input class="hm-input" type="file" name="ads_txt_file" accept=".txt,.csv,text/plain,text/csv"></label>
        <button class="hm-button-primary" data-submitting-label="Importing…">Import demand file</button>
    </form>
</article>
@endif

<div class="table-wrap"><table><thead><tr><th>Publisher / website</th><th>Domain</th><th>Status</th><th>Required</th><th>Correct</th><th>Missing</th><th>Invalid / conflict</th><th>Last check</th><th>Verification</th><th>Action required</th></tr></thead><tbody>
@forelse($sites as $site)
    @php($summary = $summaries[$site->id])
    <tr>
        <td><strong>{{ $site->publisher->display_name }}</strong><br><a class="section-anchor" href="{{ route('admin.compliance.ads-txt.show', $site) }}">{{ $site->display_name }}</a></td>
        <td>{{ $site->primary_domain }}</td><td><x-status-badge :status="$summary['status']" /></td>
        <td>{{ $summary['required_count'] }}</td><td>{{ $summary['correct_count'] }}</td><td>{{ $summary['missing_count'] }}</td><td>{{ $summary['invalid_count'] }}</td>
        <td>{{ $summary['last_checked']?->diffForHumans() ?: 'Never' }}</td><td><x-status-badge :status="$summary['verification_state']" />@if($summary['next_check_at'])<small class="table-note">Next due {{ $summary['next_check_at']->diffForHumans() }}</small>@endif</td><td>{{ $summary['action'] }}</td>
    </tr>
@empty<tr><td colspan="10">No websites are configured.</td></tr>@endforelse
</tbody></table></div>
{{ $sites->links() }}

@if(auth()->user()->hasPermission('supply_chain.ads_txt.manage'))
<article class="workspace-section secondary-workflow">
    <p class="eyebrow">Safe bulk management</p><h2>Assign one managed record to mapped websites</h2>
    <p class="muted">The operation is atomic. Every selected website must already have an explicit mapping to the selected demand account; duplicates are skipped.</p>
    <form method="POST" action="{{ route('admin.compliance.ads-txt.bulk-assign') }}" class="form-stack">@csrf
        <div class="form-grid">
            <label>Demand account<select class="hm-input" name="demand_account_id" required><option value="">Select account</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->network?->name }} · {{ $account->name }}</option>@endforeach</select></label>
            <label>Advertising system domain<input class="hm-input" name="domain" placeholder="exchange.example" required></label>
            <label>Publisher account ID<input class="hm-input" name="publisher_account_id" required></label>
            <label>Relationship<select class="hm-input" name="relationship"><option>DIRECT</option><option>RESELLER</option></select></label>
            <label>Certification authority ID<input class="hm-input" name="certification_authority_id"></label>
        </div>
        <fieldset class="selection-grid"><legend>Websites on this page</legend>@foreach($sites as $site)<label><input type="checkbox" name="site_ids[]" value="{{ $site->id }}"> {{ $site->publisher->display_name }} · {{ $site->primary_domain }}</label>@endforeach</fieldset>
        <button class="hm-button-secondary">Assign managed record</button>
    </form>
</article>
@endif
@endsection
