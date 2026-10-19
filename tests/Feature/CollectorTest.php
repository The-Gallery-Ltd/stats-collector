<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use TheGallery\StatsCollector\Backoff;
use TheGallery\StatsCollector\Jobs\SendEvents;
use TheGallery\StatsCollector\Spool;
use TheGallery\StatsCollector\StatsCollector;

function fakeAccepted(): void
{
    Http::fake(['stats.test/*' => Http::response(['accepted' => 1], 202)]);
}

function sentEvents(): array
{
    return Http::recorded()->flatMap(fn ($pair) => $pair[0]->data()['events'])->all();
}

it('records an event with the expected fields and headers', function () {
    fakeAccepted();

    $this->withHeaders([
        'User-Agent' => 'TestBrowser/1.0',
        'Referer' => 'https://google.com/',
        'Accept-Language' => 'en-GB,en;q=0.9',
    ])->get('https://www.example.com/hello?foo=bar')->assertOk();

    Http::assertSentCount(1);
    Http::assertSent(function (ClientRequest $request) {
        expect($request->url())->toBe('https://stats.test/api/ingest')
            ->and($request->method())->toBe('POST')
            ->and($request->header('Authorization'))->toBe(['Bearer test-key'])
            ->and($request->header('Accept'))->toBe(['application/json'])
            ->and($request->header('Content-Type'))->toBe(['application/json'])
            ->and($request->header('User-Agent'))->toBe(['stats-collector/'.StatsCollector::VERSION])
            ->and($request->data()['collector'])->toBe(['version' => '0.1.0']);

        $event = $request->data()['events'][0];

        expect($event['request_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
            ->and($event['ts'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/')
            ->and($event['method'])->toBe('GET')
            ->and($event['scheme'])->toBe('https')
            ->and($event['host'])->toBe('www.example.com')
            ->and($event['path'])->toBe('/hello')
            ->and($event['query'])->toBeNull()
            ->and($event['utm'])->toBeNull()
            ->and($event['status'])->toBe(200)
            ->and($event['duration_ms'])->toBeInt()->toBeGreaterThanOrEqual(0)
            ->and($event['bytes'])->toBe(strlen('hello world'))
            ->and($event['content_type'])->toBe('text/html; charset=utf-8')
            ->and($event['ip'])->toBe('127.0.0.1')
            ->and($event['user_agent'])->toBe('TestBrowser/1.0')
            ->and($event['referrer'])->toBe('https://google.com/')
            ->and($event['accept_language'])->toBe('en-GB,en;q=0.9')
            ->and($event['route'])->toBe('hello')
            ->and($event['authenticated'])->toBeFalse();

        return true;
    });
});

it('captures the query string only when enabled', function () {
    fakeAccepted();
    config(['stats-collector.capture_query' => true]);

    $this->get('/hello?foo=bar&x=1');

    expect(sentEvents()[0]['query'])->toBe('foo=bar&x=1');
});

it('always captures utm parameters, even when capture_query is off', function () {
    fakeAccepted();

    $this->get('/hello?utm_source=newsletter&utm_campaign='.str_repeat('c', 300).'&other=1');

    $event = sentEvents()[0];

    expect($event['query'])->toBeNull()
        ->and($event['utm']['source'])->toBe('newsletter')
        ->and($event['utm']['medium'])->toBeNull()
        ->and($event['utm']['campaign'])->toBe(str_repeat('c', 255));
});

it('truncates long header values', function () {
    fakeAccepted();

    $this->withHeaders(['User-Agent' => str_repeat('a', 2000)])->get('/hello');

    expect(sentEvents()[0]['user_agent'])->toHaveLength(1024);
});

it('records 404s', function () {
    fakeAccepted();

    $this->get('/nope')->assertNotFound();

    expect(sentEvents()[0])->path->toBe('/nope')->status->toBe(404)->route->toBeNull();
});

it('does not record excluded paths', function (string $path) {
    fakeAccepted();

    $this->get($path);

    Http::assertNothingSent();
})->with(['/up', '/livewire/update']);

it('is disabled when the endpoint or key is missing', function (string $key) {
    fakeAccepted();
    config(["stats-collector.{$key}" => null]);

    $this->get('/hello')->assertOk();

    Http::assertNothingSent();
})->with(['endpoint', 'key']);

it('is disabled by STATS_ENABLED=false', function () {
    fakeAccepted();
    config(['stats-collector.enabled' => false]);

    $this->get('/hello');

    Http::assertNothingSent();
});

it('spools and backs off on failure, then flushes the spool once the backoff expires', function () {
    $spool = app(Spool::class);
    $backoff = app(Backoff::class);

    $status = 503;
    Http::fake(['stats.test/*' => function () use (&$status) {
        return Http::response($status === 202 ? ['accepted' => 1] : 'down', $status);
    }]);

    // 1. Central app is down: event goes to the spool and backoff starts.
    $this->get('/hello')->assertOk();

    Http::assertSentCount(1);
    expect($spool->count())->toBe(1)
        ->and($backoff->active())->toBeTrue()
        ->and(is_file($spool->directory().'/.gitignore'))->toBeTrue();

    // 2. Central app is back, but we're within the backoff window: no network, straight to spool.
    $status = 202;
    $this->travel(30)->seconds();
    $this->get('/hello');

    Http::assertSentCount(1);
    expect($spool->count())->toBe(2);

    // 3. After the window: one send carrying both spooled events + the current one.
    $this->travel(31)->seconds();
    $this->get('/hello?page=3');

    Http::assertSentCount(2);
    $last = Http::recorded()->last()[0]->data()['events'];
    expect($last)->toHaveCount(3)
        ->and(collect($last)->pluck('request_id')->unique())->toHaveCount(3)
        ->and($spool->count())->toBe(0)
        ->and($backoff->active())->toBeFalse();
});

it('spools on connection errors', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $this->get('/hello')->assertOk();

    expect(app(Spool::class)->count())->toBe(1)
        ->and(app(Backoff::class)->active())->toBeTrue();
});

it('spools on 401 so data survives a key fix', function () {
    Http::fake(['stats.test/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $this->get('/hello');

    expect(app(Spool::class)->count())->toBe(1)
        ->and(app(Backoff::class)->active())->toBeTrue();
});

it('drops events on 422', function () {
    Http::fake(['stats.test/*' => Http::response(['message' => 'invalid'], 422)]);

    $this->get('/hello');

    Http::assertSentCount(1);
    expect(app(Spool::class)->count())->toBe(0)
        ->and(app(Backoff::class)->active())->toBeFalse();
});

it('sends just the current event when another process holds the flush lock', function () {
    fakeAccepted();
    $spool = app(Spool::class);
    $spool->append([['request_id' => 'spooled']]);

    $other = fopen($spool->path('flush.lock'), 'c');
    flock($other, LOCK_EX | LOCK_NB);

    $this->get('/hello');

    flock($other, LOCK_UN);
    fclose($other);

    expect(sentEvents())->toHaveCount(1)
        ->and($spool->count())->toBe(1);
});

it('dispatches a job with the queue driver', function () {
    Queue::fake();
    Http::fake();
    config([
        'stats-collector.driver' => 'queue',
        'stats-collector.queue.connection' => 'redis',
        'stats-collector.queue.name' => 'stats',
    ]);

    $this->get('/hello');

    Http::assertNothingSent();
    Queue::assertPushedOn('stats', SendEvents::class, fn (SendEvents $job) => $job->connection === 'redis'
        && count($job->events) === 1
        && $job->events[0]['path'] === '/hello');
});

it('spools events when a queued job finally fails', function () {
    (new SendEvents([['request_id' => 'a'], ['request_id' => 'b']]))->failed(new RuntimeException('x'));

    expect(app(Spool::class)->count())->toBe(2);
});
