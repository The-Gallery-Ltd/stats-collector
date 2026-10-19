# the-gallery-ltd/stats-collector

Per-site collector for the self-hosted stats service (our AWStats replacement). Install it in a Laravel 12 app (PHP 8.2+). It records every request that reaches Laravel and sends it to the central stats app over HTTP.

It is built so that it can't break or slow down the host site:

- The default `sync` driver sends **after** the response has gone to the visitor. It uses terminable middleware, and under PHP-FPM Symfony calls `fastcgi_finish_request()` before `terminate()` runs.
- Every failure is caught and swallowed. Set `STATS_DEBUG=true` to log failures at debug level.
- If the central app is down, events go to a local spool and network attempts stop for a backoff window, so requests never queue up behind timeouts.

## Install

The package lives in its own git repository. Add it as a VCS repository in the host app's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "git@github.com:The-Gallery-Ltd/stats-collector.git" }
]
```

```bash
composer require the-gallery-ltd/stats-collector:^0.1
```

The repository is private, so Composer needs read access to it wherever `composer install` runs. Use a fine-grained GitHub token with **Contents: read-only** on this repo only:

- **Locally:** `composer config --global github-oauth.github.com <token>`
- **In CI:** set `COMPOSER_AUTH={"github-oauth": {"github.com": "<token>"}}` from a secret

For Elastic Beanstalk, run `composer install --no-dev` in CI and deploy the bundle with `vendor/` included. Elastic Beanstalk then skips its own `composer install`, so the instances never need the token. Never commit the token (e.g. in `auth.json`).

The service provider is auto-discovered. Then add to `.env`:

```dotenv
STATS_ENDPOINT=https://stats.example.com
STATS_KEY=the-site-ingest-key
```

Check the configuration with `php artisan stats:test`.

To customise the config (for example `exclude_paths`), publish it:

```bash
php artisan vendor:publish --tag=stats-collector-config
```

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `STATS_ENABLED` | `true` | Master switch |
| `STATS_ENDPOINT` | – | Base URL of the central app. Events are POSTed to `{endpoint}/api/ingest` |
| `STATS_KEY` | – | Bearer key for this site |
| `STATS_DRIVER` | `sync` | `sync` or `queue` |
| `STATS_QUEUE_CONNECTION` / `STATS_QUEUE` | app default | Queue connection and name for the `queue` driver |
| `STATS_TIMEOUT` / `STATS_CONNECT_TIMEOUT` | `2` / `1` | HTTP timeouts in seconds |
| `STATS_CAPTURE_QUERY` | `false` | Send the raw query string. It is off by default because query strings often hold tokens or personal data |
| `STATS_AUTO_REGISTER_MIDDLEWARE` | `true` | Add the middleware to the global stack automatically |
| `STATS_SPOOL_PATH` | `storage/stats-collector` | Spool directory |
| `STATS_SPOOL_MAX_BYTES` | `52428800` (50 MB) | Above this size new events are dropped |
| `STATS_SPOOL_FLUSH_BATCH` | `500` | Number of spooled events sent along with each live send |
| `STATS_BACKOFF_SECONDS` | `60` | How long to skip the network after a failed send |
| `STATS_DEBUG` | `false` | Log swallowed failures at debug level |

Collection is switched off automatically when the endpoint or key is empty, and when the app runs in the console (artisan, queue workers, the host app's test suite).

## Middleware registration

By default the provider pushes `TheGallery\StatsCollector\Http\Middleware\CollectRequest` onto the global HTTP middleware stack. To register it yourself, set `STATS_AUTO_REGISTER_MIDDLEWARE=false` and add it in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\TheGallery\StatsCollector\Http\Middleware\CollectRequest::class);
})
```

The middleware has to be global, or attached to every group you want counted, so that it sees 404s and redirects.

These paths are excluded by default (patterns matched with `Str::is` against the path without its leading slash): `up`, `livewire/*`, `livewire-*/*`, `_debugbar/*`, `telescope/*`, `horizon/*`, `pulse/*`, `_ignition/*`, `sanctum/csrf-cookie`, `build/*`.

## Client IP behind a proxy: configure TrustProxies

The `ip` field comes from `$request->ip()`. If the site sits behind Cloudflare, a load balancer or any other reverse proxy, that value is the **proxy's** IP unless the host app trusts the proxy. In Laravel 12, set this in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(at: ['10.0.0.0/8' /* or Cloudflare ranges, or '*' if safe */]);
})
```

## What is captured

Each request becomes one event, POSTed as `{"collector": {"version": "0.1.0"}, "events": [...]}` (at most 500 events per POST):

| Field | Notes |
|---|---|
| `request_id` | UUID v4, generated per request. The central app de-duplicates on it |
| `ts` | Request start, UTC ISO-8601 with milliseconds |
| `method`, `scheme`, `host` | |
| `path` | Raw request path, always starting with `/` (max 2048 characters) |
| `query` | Raw query string, only when `STATS_CAPTURE_QUERY=true`, otherwise `null` |
| `utm` | `{"source", "medium", "campaign"}` from `utm_source` / `utm_medium` / `utm_campaign`. **Always captured**, whatever `capture_query` is set to. Each value is truncated to 255 characters. It is `null` when none of the three is present |
| `status`, `duration_ms`, `bytes`, `content_type` | `bytes` comes from Content-Length, or the body or file size. It is `null` for streamed responses |
| `ip` | See TrustProxies above |
| `user_agent`, `referrer` | Truncated to 1024 characters |
| `accept_language` | Truncated to 255 characters |
| `route` | Route name, or `null` |
| `authenticated` | `true` or `false` only. User IDs are never sent |

**Not captured:** anything that never reaches Laravel. That includes static files served directly by nginx or Apache (`/build/*`, images, CSS, JS, `robots.txt` when it exists on disk), requests that the web server or CDN blocks or caches, and requests that crash before the HTTP kernel finishes.

## Delivery: sync vs queue

**`sync` (default, no queue needed).** After the response has been sent, the collector POSTs the event straight to the central app. Each send also picks up to `flush_batch` events from the spool, so a backlog drains on its own once the central app is reachable again.

**`queue`.** Each request dispatches a `SendEvents` job on the configured connection and queue. The job makes 5 attempts with increasing backoff. If it finally fails, its events go to the spool. If dispatching itself fails, the events go straight to the spool. The queue driver does not drain the spool by itself, so schedule `stats:flush` (see below).

## Spool and backoff

- The spool is a JSONL file in `storage/stats-collector/`. The directory is created automatically with its own `.gitignore`.
- When a send fails (connection error, timeout, 5xx, or **401**), the events are spooled and a backoff window starts (`STATS_BACKOFF_SECONDS`). During the window, requests write straight to the spool and make no network call. The first send after the window carries the spooled backlog too.
- A **401** means the key is misconfigured. The events are spooled, so nothing is lost once the key is fixed, and a warning is logged at most once per backoff window.
- A **422** means the whole request envelope is invalid. The events are dropped and a warning is logged, because retrying invalid data can never succeed. (The central app skips individually invalid events and stores the rest, so a single bad event doesn't produce a 422.)
- Only one process flushes at a time (non-blocking `flock`). Other requests just send their own event.
- To flush, the collector atomically renames the spool to `spool.sending` and tracks how much has been delivered. Concurrent appends are never lost, and a crash mid-send only causes a re-send, which the central app de-duplicates.
- Once the spool reaches `STATS_SPOOL_MAX_BYTES`, new events are dropped instead of filling the disk.

## Commands

```bash
php artisan stats:test    # send a synthetic event (path /__stats-collector-test) and report success or the HTTP status/error
php artisan stats:flush   # send the whole spool now, in batches, and report counts (ignores any backoff)
```

With the queue driver, or as a safety net, schedule the flush in `routes/console.php`:

```php
Schedule::command('stats:flush')->everyFifteenMinutes()->withoutOverlapping();
```

## Development

```bash
composer install
vendor/bin/pest
```
