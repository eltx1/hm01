<?php

return [
    'cdn_url' => rtrim(env('HORUS_CDN_URL', 'https://cdn.horusmedia.net'), '/'),
    'static_config_root' => env('HORUS_STATIC_CONFIG_ROOT', public_path('cdn/configs')),
    'static_config_public_path' => trim(env('HORUS_STATIC_CONFIG_PUBLIC_PATH', 'configs'), '/'),
    'loader_url' => env('HORUS_LOADER_URL', 'https://cdn.horusmedia.net/hm-loader.js'),
    'gpt_url' => env('HORUS_GPT_URL', 'https://securepubads.g.doubleclick.net/tag/js/gpt.js'),
    'config_cache_ttl_seconds' => (int) env('HORUS_CONFIG_CACHE_TTL', 60),
];
