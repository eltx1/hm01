<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Session Driver
    |--------------------------------------------------------------------------
    |
    | This option determines the default session driver that is utilized for
    | incoming requests. Laravel supports a variety of storage options to
    | persist session data. Database storage is a great default choice.
    |
    | Supported: "file", "cookie", "database", "memcached",
    |            "redis", "dynamodb", "array"
    |
    */

    'driver' => env('SESSION_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Session Lifetime
    |--------------------------------------------------------------------------
    |
    | Here you may specify the number of minutes that you wish the session to
    | be allowed to remain idle before it expires. If you want them to expire
    | immediately when the browser is closed, use the expire-on-close option.
    |
    */

    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    'expire_on_close' => env('SESSION_EXPIRE_ON_CLOSE', false),

    /*
    |--------------------------------------------------------------------------
    | Session Encryption
    |--------------------------------------------------------------------------
    */

    'encrypt' => env('SESSION_ENCRYPT', false),

    /*
    |--------------------------------------------------------------------------
    | Session File Location
    |--------------------------------------------------------------------------
    */

    'files' => storage_path('framework/sessions'),

    /*
    |--------------------------------------------------------------------------
    | Session Database Connection / Table
    |--------------------------------------------------------------------------
    */

    'connection' => env('SESSION_CONNECTION') ?: null,

    'table' => env('SESSION_TABLE') ?: 'sessions',

    /*
    |--------------------------------------------------------------------------
    | Session Cache Store
    |--------------------------------------------------------------------------
    */

    'store' => env('SESSION_STORE') ?: null,

    /*
    |--------------------------------------------------------------------------
    | Session Sweeping Lottery
    |--------------------------------------------------------------------------
    */

    'lottery' => [2, 100],

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Name
    |--------------------------------------------------------------------------
    |
    | Blank environment placeholders must not become an invalid cookie name.
    |
    */

    'cookie' => env('SESSION_COOKIE') ?: Str::slug((string) env('APP_NAME', 'laravel')).'-session',

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Path / Domain
    |--------------------------------------------------------------------------
    */

    'path' => env('SESSION_PATH') ?: '/',

    'domain' => env('SESSION_DOMAIN') ?: null,

    /*
    |--------------------------------------------------------------------------
    | HTTPS / HTTP-only Cookies
    |--------------------------------------------------------------------------
    */

    'secure' => env('SESSION_SECURE_COOKIE', true),

    'http_only' => env('SESSION_HTTP_ONLY', true),

    /*
    |--------------------------------------------------------------------------
    | Same-Site / Partitioned Cookies
    |--------------------------------------------------------------------------
    */

    'same_site' => env('SESSION_SAME_SITE') ?: 'lax',

    'partitioned' => env('SESSION_PARTITIONED_COOKIE', false),

];
