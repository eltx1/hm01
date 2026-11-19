@props(['opportunities', 'currency' => 'USD', 'publisher' => false])
@if(collect($opportunities)->isNotEmpty())
<section class="ad-format-opportunities" aria-labelledby="ad-format-opportunities-heading">
    <h3 id="ad-format-opportunities-heading">Revenue opportunities</h3>
    <ul>
        @foreach($opportunities as $item)
            <li data-ad-format-opportunity="{{ $item['format'] }}"><strong>Enable {{ $item['label'] }}</strong>
                · estimated +{{ \App\Support\Money::formatMinor($item['estimated_minor']) }} {{ $currency }} per 30 days
                <span class="muted">({{ $item['label'] }} is {{ number_format($item['median_share_bp'] / 100, 0) }}% of revenue on {{ $item['peer_sites'] }} comparable websites)</span></li>
        @endforeach
    </ul>
    <p class="report-footnote muted">Estimates use the median share across at least three websites on the network over the last 30 days{{ $publisher ? ' and your own revenue share' : '' }}. Actual results depend on your layout and traffic.@if($publisher) Contact your account team to enable a format.@endif</p>
</section>
@endif
