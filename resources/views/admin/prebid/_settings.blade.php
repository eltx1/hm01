<section class="detail-grid">
<article>
    <p class="eyebrow">Browser runtime</p><h2>Auction settings</h2>
    <form class="form-stack" method="POST" action="{{ route('admin.sites.prebid.settings', [$site, $connection]) }}">
        @csrf @method('PUT')
        <label><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($settings?->enabled)> Enable Prebid for this website and GAM network</label>
        <label>Compiled Prebid build
            <select class="hm-input" name="prebid_build_id">
                <option value="">Active build</option>
                @foreach($builds as $build)<option value="{{ $build->id }}" @selected($settings?->prebid_build_id === $build->id)>{{ $build->version }} · {{ $build->status }}</option>@endforeach
            </select>
        </label>
        <label>Price bucket
            <select class="hm-input" name="prebid_price_bucket_id">
                <option value="">Default for this network</option>
                @foreach($buckets as $bucket)<option value="{{ $bucket->id }}" @selected($settings?->prebid_price_bucket_id === $bucket->id)>{{ $bucket->name }} · {{ $bucket->currency_code }}</option>@endforeach
            </select>
        </label>
        <label>Auction timeout ms<input class="hm-input" type="number" min="300" max="5000" name="auction_timeout_ms" value="{{ $settings?->auction_timeout_ms ?? config('prebid.default_timeout_ms') }}" required></label>
        <label>Price granularity<select class="hm-input" name="price_granularity">@foreach(['custom','low','medium','high','auto','dense'] as $value)<option value="{{ $value }}" @selected(($settings?->price_granularity ?? 'custom') === $value)>{{ $value }}</option>@endforeach</select></label>
        <label>Currency<input class="hm-input" name="currency_code" maxlength="3" value="{{ $settings?->currency_code ?? 'USD' }}" required></label>
        <label>Bidder sequence<select class="hm-input" name="bidder_sequence"><option value="random" @selected(($settings?->bidder_sequence ?? 'random') === 'random')>random</option><option value="fixed" @selected($settings?->bidder_sequence === 'fixed')>fixed</option></select></label>
        <label>Consent behavior JSON<textarea class="hm-input" rows="5" name="consent_behavior_json">{{ json_encode($settings?->consent_behavior ?? ['gdpr'=>['cmpApi'=>'iab','timeout'=>800,'allowAuctionWithoutConsent'=>false],'gpp'=>['cmpApi'=>'iab','timeout'=>800]], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</textarea></label>
        <label>Lazy loading JSON<textarea class="hm-input" rows="3" name="lazy_loading_json">{{ json_encode($settings?->lazy_loading ?? ['enabled'=>true], JSON_PRETTY_PRINT) }}</textarea></label>
        <label>Refresh behavior JSON<textarea class="hm-input" rows="3" name="refresh_behavior_json">{{ json_encode($settings?->refresh_behavior ?? ['enabled'=>true,'auctionBeforeRefresh'=>true], JSON_PRETTY_PRINT) }}</textarea></label>
        <label><input type="hidden" name="timeout_reporting" value="0"><input type="checkbox" name="timeout_reporting" value="1" @checked($settings?->timeout_reporting)> Browser-only timeout diagnostics</label>
        <label><input type="hidden" name="gam_fallback" value="0"><input type="checkbox" name="gam_fallback" value="1" @checked($settings?->gam_fallback ?? true)> Request GAM when no bid or Prebid fails</label>
        <button class="hm-button-primary">Save and publish production configuration</button>
    </form>
</article>

<article>
    <p class="eyebrow">Price granularity</p><h2>Create price bucket</h2>
    <form class="form-stack" method="POST" action="{{ route('admin.sites.prebid.price-buckets.store', [$site, $connection]) }}">
        @csrf
        <label>Name<input class="hm-input" name="name" value="Standard USD 0.05" required></label>
        <label>Code<input class="hm-input" name="code" value="standard-usd" required></label>
        <label>Currency<input class="hm-input" name="currency_code" value="USD" maxlength="3" required></label>
        <label>Ranges JSON<textarea class="hm-input" rows="6" name="ranges_json" required>[
  {"min":0,"max":5,"increment":0.05,"precision":2}
]</textarea></label>
        <label><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1"> Default network bucket</label>
        <label><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" checked> Enabled</label>
        <button class="hm-button-secondary">Save bucket</button>
    </form>
    <p class="muted">Changing buckets requires a new dry-run because it can add or remove many GAM line items.</p>
</article>
</section>
