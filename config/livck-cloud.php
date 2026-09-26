<?php

declare(strict_types=1);

use LIVCK\Cloud\ClientOptions;

return [

    /*
    |--------------------------------------------------------------------------
    | Default connection
    |--------------------------------------------------------------------------
    |
    | The connection behind the LivckCloud facade, an injected
    | CloudClientInterface and `php artisan livck-cloud:check`.
    |
    */

    'default' => env('LIVCK_CLOUD_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | One connection per organization, each with that organization's API token
    | (lvk_…, under Settings > Organization > API tokens). A reseller serving
    | several organizations adds one entry per token and picks it with
    | LivckCloud::connection('name').
    |
    | locale     null sends no Accept-Language; "de" or "en" sends that one;
    |            "app" sends the application's locale at the time of each call.
    |
    | transport  "sdk" sends through the SDK's own Guzzle client. "laravel"
    |            sends through Laravel's HTTP client, so that Http::fake(),
    |            Http::preventStrayRequests(), Telescope and Pulse see every
    |            request.
    |
    */

    'connections' => [

        'default' => [
            'token' => env('LIVCK_CLOUD_TOKEN'),
            'base_uri' => env('LIVCK_CLOUD_BASE_URI', ClientOptions::DEFAULT_BASE_URI),
            'timeout' => env('LIVCK_CLOUD_TIMEOUT', 30),
            'connect_timeout' => env('LIVCK_CLOUD_CONNECT_TIMEOUT', 10),
            'max_retries' => env('LIVCK_CLOUD_MAX_RETRIES', 2),
            'max_retry_after' => env('LIVCK_CLOUD_MAX_RETRY_AFTER', 60),
            'idempotency' => env('LIVCK_CLOUD_IDEMPOTENCY', true),
            'locale' => env('LIVCK_CLOUD_LOCALE'),
            'user_agent_suffix' => env('LIVCK_CLOUD_USER_AGENT_SUFFIX'),
            'transport' => env('LIVCK_CLOUD_TRANSPORT', 'sdk'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Log channel
    |--------------------------------------------------------------------------
    |
    | A channel of config/logging.php that receives one debug line per request
    | attempt: method, URI, status, duration. Never a token, never a body.
    | null logs nothing.
    |
    */

    'log_channel' => env('LIVCK_CLOUD_LOG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Check-type catalog cache
    |--------------------------------------------------------------------------
    |
    | LivckCloud::catalog() keeps the check-type catalog in this cache store
    | (null for the default store) for ttl seconds, per connection, base URI
    | and locale. 0 turns the cache off. checkTypes() always asks the API.
    |
    */

    'catalog_cache' => [
        'store' => env('LIVCK_CLOUD_CATALOG_CACHE_STORE'),
        'ttl' => env('LIVCK_CLOUD_CATALOG_CACHE_TTL', 3600),
    ],

];
