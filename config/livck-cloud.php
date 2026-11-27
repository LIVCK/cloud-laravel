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

    /*
    |--------------------------------------------------------------------------
    | Heartbeats
    |--------------------------------------------------------------------------
    |
    | Scheduled tasks marked with ->heartbeat(), scheduled queue jobs and the
    | queues below ping their LIVCK heartbeat. Only in these environments;
    | anywhere else the package adds no hooks, listeners or pulse.
    |
    | With an API token that has heartbeats.declare, the scheduler declares
    | the heartbeats to LIVCK, which creates and updates them, and keeps their
    | ping URLs in the cache. A key with a URL under `urls` is pinged at that
    | URL instead and never declared, which works on every plan.
    |
    | app              The application's name in LIVCK; app.name as a slug when
    |                  null.
    |
    | queues           Queues the scheduler sends a pulse through every minute. A
    |                  plain entry is a queue of the default queue connection
    |                  (heartbeat queue:<name>), a string key names another one:
    |                  ['default', 'mails', 'sqs' => ['reports']] (queue:sqs:reports).
    |
    | queue_grace      Seconds a queue's pulse may be late, 30 at least. With the
    |                  default of 120, a queue outlasts a short `artisan down` or
    |                  a deploy, and a queue no worker takes is reported after
    |                  about four minutes.
    |
    | custom           Heartbeats your own code pings (LivckCloud::heartbeat()),
    |                  by key, with the most seconds expected between two pings,
    |                  so that they can be declared: ['import' => 3600].
    |
    | urls             Ping URLs by heartbeat key, copied from the heartbeat in
    |                  LIVCK. A key with a URL here is pinged at exactly that URL.
    |
    | timeout          Seconds a ping may take. A ping is never retried.
    |
    | declare_timeout  Seconds the scheduler waits for a declaration, which is
    |                  never retried either; the next try comes a minute later.
    |
    | cache_store      The store for ping URLs, the state of the declaration and
    |                  the pulse's lock, null for the default store. The
    |                  scheduler and the queue workers must share it.
    |
    */

    'heartbeats' => [
        'app' => env('LIVCK_CLOUD_APP'),
        'environments' => ['production'],
        'queues' => [],
        'queue_grace' => env('LIVCK_CLOUD_HEARTBEAT_QUEUE_GRACE', 120),
        'custom' => [
            // 'import' => 3600,
        ],
        'urls' => [
            // 'export:nightly' => env('LIVCK_HEARTBEAT_EXPORT_NIGHTLY'),
        ],
        'timeout' => env('LIVCK_CLOUD_HEARTBEAT_TIMEOUT', 3),
        'declare_timeout' => env('LIVCK_CLOUD_HEARTBEAT_DECLARE_TIMEOUT', 3),
        'cache_store' => env('LIVCK_CLOUD_HEARTBEAT_CACHE_STORE'),
    ],

];
