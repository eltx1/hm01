<?php

return [
    'cdn_url' => rtrim(env('HORUS_CDN_URL', 'https://cdn.horusmedia.net') ?: 'https://cdn.horusmedia.net', '/'),
    'loader_url' => env('HORUS_LOADER_URL', 'https://cdn.horusmedia.net/hm-loader.js') ?: 'https://cdn.horusmedia.net/hm-loader.js',
    'gpt_url' => env('HORUS_GPT_URL', 'https://securepubads.g.doubleclick.net/tag/js/gpt.js') ?: 'https://securepubads.g.doubleclick.net/tag/js/gpt.js',
    'config_cache_ttl_seconds' => (int) (env('HORUS_CONFIG_CACHE_TTL', 60) ?: 60),
];
