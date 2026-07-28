<article>
<div class="section-heading"><div><p class="eyebrow">Centralized GAM automation</p><h2>{{ $connection->name }}</h2></div><span class="pill">{{ ($setupStatus['complete'] ?? false) ? 'Complete' : 'Incomplete' }}</span></div>
<p>Horus creates the Prebid advertiser, orders, price-bucket line items, universal creatives, targeting keys and required values. Publishers create nothing manually.</p>
@if($setupStatus)
<div class="status-row">
    <span class="pill">Expected {{ $setupStatus['expected'] }}</span>
    <span class="pill">Mapped {{ $setupStatus['mapped'] }}</span>
    <span class="pill">Missing {{ $setupStatus['missing_count'] }}</span>
</div>
@endif
<form method="POST" action="{{ route('admin.sites.prebid.setup.preview', [$site, $connection]) }}">
    @csrf
    <button class="hm-button-secondary">Dry-run and estimate GAM objects</button>
</form>
@if($template)
<p class="muted">Template: {{ $template->name }} · {{ $template->priceBucket?->name }} · max {{ $template->max_line_items_per_order }} line items/order.</p>
@endif
</article>

<article>
<h2>Setup runs</h2>
<div class="table-wrap"><table><thead><tr><th>Status</th><th>Estimate</th><th>Progress</th><th>Started</th><th>Action</th></tr></thead><tbody>
@forelse($runs as $run)
<tr>
<td><span class="pill">{{ $run->status }}</span></td>
<td>{{ number_format($run->estimated_objects) }}</td>
<td>{{ data_get($run->counters, 'processed', 0) }} / {{ data_get($run->counters, 'total', 0) }} · remaining {{ data_get($run->counters, 'remaining', 0) }}</td>
<td>{{ $run->created_at }}</td>
<td>
@if($run->status === 'PREVIEW')
<form class="form-stack" method="POST" action="{{ route('admin.sites.prebid.setup.execute', [$site, $run]) }}">
    @csrf
    <input class="hm-input" name="confirmation" placeholder="{{ config('prebid.confirmation_phrase') }}" required>
    <input class="hm-input" type="number" min="1" max="500" name="batch_size" value="100">
    <button class="hm-button-danger">Confirm external GAM writes</button>
</form>
@elseif(in_array($run->status, ['PARTIAL','FAILED'], true))
<form method="POST" action="{{ route('admin.sites.prebid.setup.resume', [$site, $run]) }}">@csrf<input type="hidden" name="batch_size" value="100"><button class="hm-button-primary">Resume safely</button></form>
@endif
</td>
</tr>
@empty<tr><td colspan="5" class="muted">Run a dry-run first. It makes no external write.</td></tr>@endforelse
</tbody></table></div>
</article>

<section class="detail-grid">
<article>
<h3>Bulk dry-run</h3>
<form class="form-stack" method="POST" action="{{ route('admin.prebid.setup.bulk.preview') }}">
@csrf
@foreach($connections as $item)<label><input type="checkbox" name="gam_connection_ids[]" value="{{ $item->id }}" @checked($item->id === $connection->id)> {{ $item->name }} · {{ $item->type->value }}</label>@endforeach
<button class="hm-button-secondary">Preview selected GAM networks</button>
</form>
</article>
<article>
<h3>Bulk confirmed setup</h3>
<form class="form-stack" method="POST" action="{{ route('admin.prebid.setup.bulk.execute') }}">
@csrf
@foreach($bulkRuns as $run)<label><input type="checkbox" name="prebid_setup_run_ids[]" value="{{ $run->id }}"> {{ $run->connection?->name ?? $run->gam_connection_id }} · {{ $run->estimated_objects }} objects</label>@endforeach
<input class="hm-input" name="confirmation" placeholder="{{ config('prebid.confirmation_phrase') }}" required>
<input class="hm-input" type="number" min="1" max="500" name="batch_size" value="100">
<button class="hm-button-danger">Execute confirmed bulk setup</button>
</form>
</article>
</section>
