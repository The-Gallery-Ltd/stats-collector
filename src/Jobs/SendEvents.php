<?php

declare(strict_types=1);

namespace Secarma\StatsCollector\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Secarma\StatsCollector\Exceptions\TransportException;
use Secarma\StatsCollector\Recorder;
use Secarma\StatsCollector\Spool;
use Secarma\StatsCollector\Transport\HttpTransport;
use Throwable;

/**
 * Queue driver job. Deliberately avoids the illuminate/bus & illuminate/queue
 * traits so the package only depends on contracts.
 */
final class SendEvents implements ShouldQueue
{
    public int $tries = 5;

    public ?string $connection = null;

    public ?string $queue = null;

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(public array $events) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function onConnection(?string $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function onQueue(?string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    public function handle(HttpTransport $transport): void
    {
        try {
            $transport->send($this->events);
        } catch (TransportException $e) {
            if ($e->isInvalidPayload()) {
                Recorder::warning('Central stats app rejected events as invalid (422); dropping them.', ['count' => count($this->events)]);

                return;
            }

            throw $e;
        }
    }

    /**
     * All retries exhausted: keep the events in the local spool so
     * `stats:flush` (or the next sync send) can deliver them later.
     */
    public function failed(?Throwable $e = null): void
    {
        try {
            app(Spool::class)->append($this->events);
        } catch (Throwable) {
            // Nothing else we can do.
        }
    }
}
