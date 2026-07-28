<?php

return [
    'version' => env('PREBID_VERSION', '11.14.0'),
    'source_repository' => env('PREBID_SOURCE_REPOSITORY', 'https://github.com/prebid/Prebid.js.git'),
    'source_reference' => env('PREBID_SOURCE_REFERENCE', '11.14.0'),
    'default_build_version' => env('PREBID_BUILD_VERSION', 'horus-11.14.0-1'),
    'default_asset_path' => 'assets/prebid/horus-prebid.js',
    'default_minified_path' => 'assets/prebid/horus-prebid.min.js',
    'default_manifest_path' => 'assets/prebid/manifest.json',
    'default_modules' => [
        'appnexusBidAdapter',
        'ixBidAdapter',
        'openxBidAdapter',
        'pubmaticBidAdapter',
        'rubiconBidAdapter',
        'consentManagementTcf',
        'consentManagementGpp',
        'gptPreAuction',
        'currency',
    ],
    'auction_timeout_ms' => (int) env('PREBID_AUCTION_TIMEOUT_MS', 1200),
    'currency' => env('PREBID_CURRENCY', 'USD'),
    'price_granularity' => env('PREBID_PRICE_GRANULARITY', 'DENSE'),
    'setup' => [
        'advertiser_name' => env('PREBID_GAM_ADVERTISER_NAME', 'Horus Media Prebid'),
        'order_prefix' => env('PREBID_GAM_ORDER_PREFIX', 'Horus Prebid'),
        'line_item_priority' => (int) env('PREBID_GAM_LINE_ITEM_PRIORITY', 12),
        'targeting_keys' => ['hb_pb', 'hb_adid', 'hb_bidder', 'hb_format', 'hb_size'],
        'universal_creative' => <<<'HTML'
<script>
(function(){
  var w = window;
  for (var i = 0; i < 10; i++) {
    w = w.parent;
    if (w.pbjs && w.pbjs.renderAd) {
      w.pbjs.renderAd(document, '%%PATTERN:hb_adid%%');
      return;
    }
  }
}());
</script>
HTML,
    ],
    'price_buckets' => [
        ['label' => 'dense-0-3', 'minimum' => 0, 'maximum' => 3, 'increment' => 0.01, 'precision' => 2, 'priority' => 10],
        ['label' => 'dense-3-8', 'minimum' => 3, 'maximum' => 8, 'increment' => 0.05, 'precision' => 2, 'priority' => 20],
        ['label' => 'dense-8-20', 'minimum' => 8, 'maximum' => 20, 'increment' => 0.50, 'precision' => 2, 'priority' => 30],
    ],
];
