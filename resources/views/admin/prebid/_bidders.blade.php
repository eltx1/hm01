<article>
<div class="section-heading"><div><p class="eyebrow">Bidder registry</p><h2>Accounts and mappings</h2></div><span class="pill">{{ $accounts->count() }} accounts</span></div>
<form class="form-grid" method="POST" action="{{ route('admin.sites.prebid.accounts.store', $site) }}">
    @csrf
    <label>Adapter<select class="hm-input" name="prebid_adapter_id">@foreach($adapters as $adapter)<option value="{{ $adapter->id }}" @disabled(!$adapter->enabled)>{{ $adapter->adapter_name }} · {{ $adapter->bidder_code }}</option>@endforeach</select></label>
    <label>Account name<input class="hm-input" name="name" placeholder="PubMatic main account" required></label>
    <label>Publisher ID<input class="hm-input" name="publisher_id" placeholder="Public publisher/account ID"></label>
    <label class="full">Public parameters JSON<textarea class="hm-input" rows="4" name="public_parameters_json" placeholder='{"publisherId":"12345"}'></textarea></label>
    <label><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" checked> Enabled</label>
    <div><button class="hm-button-primary">Add bidder account</button></div>
</form>
</article>

@forelse($accounts as $account)
<article>
<div class="section-heading">
    <div><p class="eyebrow">{{ $account->bidder->adapter->bidder_code }}</p><h3>{{ $account->name }}</h3><p class="muted">{{ $account->bidder->adapter->adapter_name }} · Publisher ID {{ $account->publisher_id ?: 'inside parameters' }}</p></div>
    <form method="POST" action="{{ route('admin.sites.prebid.accounts.toggle', [$site, $account]) }}">@csrf @method('PATCH')<input type="hidden" name="enabled" value="{{ $account->enabled ? 0 : 1 }}"><button class="{{ $account->enabled ? 'hm-button-danger' : 'hm-button-primary' }}">{{ $account->enabled ? 'Disable bidder' : 'Enable bidder' }}</button></form>
</div>
<div class="detail-grid">
<div>
<h4>Website assignment</h4>
<form class="form-stack" method="POST" action="{{ route('admin.sites.prebid.accounts.assign-site', [$site, $account]) }}">
    @csrf
    <input type="hidden" name="gam_connection_id" value="{{ $connection->id }}">
    <label>Sequence<input class="hm-input" type="number" min="0" max="65535" name="sequence" value="100"></label>
    <label>Website parameters JSON<textarea class="hm-input" rows="3" name="public_parameters_json">{}</textarea></label>
    <label><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" checked> Enabled on this network</label>
    <button class="hm-button-secondary">Assign to website</button>
</form>
</div>
<div>
<h4>Placement overrides</h4>
@foreach($site->placements as $placement)
<form class="form-stack" method="POST" action="{{ route('admin.sites.prebid.accounts.assign-placement', [$site, $placement, $account]) }}">
    @csrf
    <input type="hidden" name="gam_connection_id" value="{{ $connection->id }}">
    <strong>{{ $placement->name }} · {{ $placement->code }}</strong>
    <textarea class="hm-input" rows="2" name="public_parameters_json" placeholder='{"adSlot":"placement-id"}'>{}</textarea>
    <label><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" checked> Enabled</label>
    <button class="hm-button-secondary">Save placement ID/parameters</button>
</form>
@endforeach
</div>
</div>
</article>
@empty
<article><p class="muted">No bidder accounts. Add an account, then assign it to the website or individual placements.</p></article>
@endforelse
