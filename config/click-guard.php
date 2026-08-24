<?php

return [
    // Client-only protection is enabled for every website unless an administrator
    // explicitly creates a site override. No visitor request reaches Laravel.
    'enabled' => env('CLICK_GUARD_ENABLED', true),
    'max_clicks' => env('CLICK_GUARD_MAX_CLICKS', 3),
    'window_hours' => env('CLICK_GUARD_WINDOW_HOURS', 6),
    'block_hours' => env('CLICK_GUARD_BLOCK_HOURS', 12),
];
