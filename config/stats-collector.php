<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch. Collection is also disabled automatically when the
    | endpoint or key is empty, and when the application is running in the
    | console (artisan, queue workers, the host app's own test suite).
    |
    */

    'enabled' => (bool) env('STATS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Central stats app
    |--------------------------------------------------------------------------
    |
    | Base URL of the central stats app (events are POSTed to
    | {endpoint}/api/ingest) and this site's ingest key.
    |
    */

    'endpoint' => env('STATS_ENDPOINT'),

    'key' => env('STATS_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Delivery driver
    |--------------------------------------------------------------------------
    |
    | "sync"  - send over HTTP after the response has been sent to the visitor
    |           (terminable middleware). Needs no queue. Default.
    | "queue" - dispatch a job per request on the given connection / queue.
    |
    */

    'driver' => env('STATS_DRIVER', 'sync'),

    'queue' => [
        'connection' => env('STATS_QUEUE_CONNECTION'),
        'name' => env('STATS_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP timeouts (seconds)
    |--------------------------------------------------------------------------
    */

    'timeout' => (float) env('STATS_TIMEOUT', 2),

    'connect_timeout' => (float) env('STATS_CONNECT_TIMEOUT', 1),

    /*
    |--------------------------------------------------------------------------
    | Capture query strings
    |--------------------------------------------------------------------------
    |
    | Query strings frequently contain tokens and personal data, so they are
    | not sent unless explicitly enabled.
    |
    */

    'capture_query' => (bool) env('STATS_CAPTURE_QUERY', false),

    /*
    |--------------------------------------------------------------------------
    | Middleware registration
    |--------------------------------------------------------------------------
    |
    | When true the package appends its middleware to the global HTTP
    | middleware stack. Set to false to register it yourself.
    |
    */

    'auto_register_middleware' => (bool) env('STATS_AUTO_REGISTER_MIDDLEWARE', true),

    /*
    |--------------------------------------------------------------------------
    | Excluded paths
    |--------------------------------------------------------------------------
    |
    | Patterns matched with Str::is() against the request path without its
    | leading slash.
    |
    */

    'exclude_paths' => [
        'up',
        'livewire/*',
        'livewire-*/*',
        '_debugbar/*',
        'telescope/*',
        'horizon/*',
        'pulse/*',
        '_ignition/*',
        'sanctum/csrf-cookie',
        'build/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Spool
    |--------------------------------------------------------------------------
    |
    | Events that could not be delivered are appended to a local JSONL spool
    | and re-sent later. Once the spool reaches max_bytes, new events are
    | dropped. flush_batch is how many spooled events are piggy-backed onto
    | each successful send.
    |
    */

    'spool' => [
        'path' => env('STATS_SPOOL_PATH', storage_path('stats-collector')),
        'max_bytes' => (int) env('STATS_SPOOL_MAX_BYTES', 50 * 1024 * 1024),
        'flush_batch' => (int) env('STATS_SPOOL_FLUSH_BATCH', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backoff
    |--------------------------------------------------------------------------
    |
    | After a failed send, skip network attempts for this many seconds and
    | write events straight to the spool, so an unreachable central server
    | does not make every request wait for a timeout.
    |
    */

    'backoff_seconds' => (int) env('STATS_BACKOFF_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Debug logging
    |--------------------------------------------------------------------------
    |
    | Failures are always swallowed. When true they are also logged at debug
    | level. (401 / 422 responses are logged as warnings regardless.)
    |
    */

    'debug' => (bool) env('STATS_DEBUG', false),

];
