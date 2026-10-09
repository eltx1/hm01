<?php

return [
    'cdn_url' => rtrim(env('HORUS_CDN_URL', 'https://cdn.horusmedia.net') ?: 'https://cdn.horusmedia.net', '/'),
    'loader_url' => env('HORUS_LOADER_URL', 'https://cdn.horusmedia.net/hm-loader.js') ?: 'https://cdn.horusmedia.net/hm-loader.js',
    'gpt_url' => env('HORUS_GPT_URL', 'https://securepubads.g.doubleclick.net/tag/js/gpt.js') ?: 'https://securepubads.g.doubleclick.net/tag/js/gpt.js',
    'video_content_url' => env('HORUS_VIDEO_CONTENT_URL') ?: null,
    'video_autoplay_audio' => 'muted',
    'video_mid_roll_interval_seconds' => 60,
    'config_cache_ttl_seconds' => (int) (env('HORUS_CONFIG_CACHE_TTL', 60) ?: 60),
];
