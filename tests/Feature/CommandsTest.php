<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Secarma\StatsCollector\Backoff;
use Secarma\StatsCollector\Spool;

it('stats:test reports success', function () {
    Http::fake(['stats.test/*' => Http::response(['accepted' => 1], 202)]);

    $this->artisan('stats:test')
        ->expectsOutputToContain('Success')
        ->assertSuccessful();

    Http::assertSent(fn (ClientRequest $r) => $r->data()['events'][0]['path'] === '/__stats-collector-test'
        && $r->data()['events'][0]['host'] === 'site.test');
});

it('stats:test reports the HTTP status on failure', function () {
    Http::fake(['stats.test/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $this->artisan('stats:test')
        ->expectsOutputToContain('HTTP 401')
        ->assertFailed();
});

it('stats:test fails when not configured', function () {
    config(['stats-collector.key' => '']);

    $this->artisan('stats:test')->assertFailed();
});

it('stats:flush sends the whole spool in batches', function () {
    Http::fake(['stats.test/*' => Http::response(['accepted' => 1], 202)]);
    config(['stats-collector.spool.flush_batch' => 2]);

    $spool = app(Spool::class);
    $spool->append(array_map(fn ($i) => ['request_id' => "id-{$i}"], range(1, 5)));
    app(Backoff::class)->start();

    $this->artisan('stats:flush')
        ->expectsOutputToContain('Sent: 5')
        ->assertSuccessful();

    Http::assertSentCount(3);
    expect($spool->count())->toBe(0)
        ->and(app(Backoff::class)->active())->toBeFalse();
});

it('stats:flush keeps the spool when the central app is down', function () {
    Http::fake(['stats.test/*' => Http::response('down', 500)]);

    $spool = app(Spool::class);
    $spool->append([['request_id' => 'a'], ['request_id' => 'b']]);

    $this->artisan('stats:flush')->assertFailed();

    expect($spool->count())->toBe(2);
});

it('chunks large sends into 500-event requests', function () {
    Http::fake(['stats.test/*' => Http::response(['accepted' => 1], 202)]);
    config(['stats-collector.spool.flush_batch' => 1200]);

    app(Spool::class)->append(array_map(fn ($i) => ['request_id' => "id-{$i}"], range(1, 1200)));

    $this->artisan('stats:flush')->assertSuccessful();

    Http::assertSentCount(3);
});

it('stops appending once the spool reaches max_bytes', function () {
    config(['stats-collector.spool.max_bytes' => 100]);
    $spool = app(Spool::class);

    expect($spool->append([['request_id' => str_repeat('a', 40)]]))->toBe(1)
        ->and($spool->append([['request_id' => str_repeat('b', 80)]]))->toBe(0)
        ->and($spool->count())->toBe(1);
});

it('does not lose events appended while a flush is in progress', function () {
    Http::fake(['stats.test/*' => Http::response('down', 500)]);
    $spool = app(Spool::class);
    $spool->append([['request_id' => 'a']]);

    expect($spool->tryLock())->toBeTrue();
    $claimed = $spool->claim(10);
    $spool->append([['request_id' => 'b']]); // concurrent request
    $spool->unlock(); // send failed: no ack

    expect($claimed)->toHaveCount(1)->and($spool->count())->toBe(2);

    $spool->tryLock();
    $first = $spool->claim(10);
    $spool->ack();
    $second = $spool->claim(10);
    $spool->ack();
    $spool->unlock();

    expect(array_column([...$first, ...$second], 'request_id'))->toBe(['a', 'b'])
        ->and($spool->count())->toBe(0);
});
