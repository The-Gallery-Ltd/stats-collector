<?php

declare(strict_types=1);

namespace Secarma\StatsCollector;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Secarma\StatsCollector\Exceptions\TransportException;
use Secarma\StatsCollector\Jobs\SendEvents;
use Secarma\StatsCollector\Transport\HttpTransport;
use Throwable;

/**
 * Delivers events using the configured driver. Never throws from record().
 */
final readonly class Recorder
{
    public function __construct(
        private Settings $settings,
        private HttpTransport $transport,
        private Spool $spool,
        private Backoff $backoff,
        private Dispatcher $bus,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function record(array $event): void
    {
        try {
            if ($this->settings->driver() === 'queue') {
                $this->dispatch([$event]);
            } else {
                $this->sendSync($event);
            }
        } catch (Throwable $e) {
            self::debug('Unexpected failure recording event', $e);
        }
    }

    /**
     * Send the whole spool now, in batches. Ignores (and on success clears)
     * any backoff window.
     *
     * @return array{sent: int, dropped: int, remaining: int, error: ?string}
     */
    public function flush(): array
    {
        $sent = $dropped = 0;

        if (! $this->spool->tryLock()) {
            return ['sent' => 0, 'dropped' => 0, 'remaining' => $this->spool->count(), 'error' => 'Another process is currently flushing the spool.'];
        }

        try {
            while (($batch = $this->spool->claim($this->settings->flushBatch())) !== []) {
                try {
                    $this->transport->send($batch);
                    $sent += count($batch);
                } catch (TransportException $e) {
                    if (! $e->isInvalidPayload()) {
                        $this->backoff->start();

                        return ['sent' => $sent, 'dropped' => $dropped, 'remaining' => $this->spool->count(), 'error' => $e->getMessage()];
                    }

                    $dropped += count($batch);
                    self::warning('Central stats app rejected spooled events as invalid (422); dropping them.', ['count' => count($batch)]);
                }

                $this->spool->ack();
            }

            $this->backoff->clear();
        } finally {
            $this->spool->unlock();
        }

        return ['sent' => $sent, 'dropped' => $dropped, 'remaining' => $this->spool->count(), 'error' => null];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function sendSync(array $event): void
    {
        if ($this->backoff->active()) {
            $this->spool->append([$event]);

            return;
        }

        $locked = $this->spool->tryLock();

        try {
            $spooled = $locked ? $this->spool->claim($this->settings->flushBatch()) : [];

            try {
                $this->transport->send([...$spooled, $event]);
            } catch (TransportException $e) {
                $this->handleFailure($e, [$event], count($spooled));

                return;
            }

            if ($locked) {
                $this->spool->ack();
            }

            $this->backoff->clear();
        } finally {
            if ($locked) {
                $this->spool->unlock();
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $events  Events not yet in the spool.
     * @param  int  $claimed  Spooled events that were part of the failed send (left in place).
     */
    private function handleFailure(TransportException $e, array $events, int $claimed): void
    {
        if ($e->isInvalidPayload()) {
            // Retrying invalid data can never succeed: drop it, including the
            // claimed spool batch, so one bad event can't wedge the spool.
            if ($claimed > 0) {
                $this->spool->ack();
            }

            self::warning('Central stats app rejected events as invalid (422); dropping them.', ['count' => count($events) + $claimed]);

            return;
        }

        $this->spool->append($events);
        $this->backoff->start();

        if ($e->isUnauthorized()) {
            // Logged at most once per backoff window, since no sends happen inside it.
            self::warning('Central stats app rejected the ingest key (401); spooling events. Check STATS_KEY.');
        } else {
            self::debug('Send failed; events spooled and backoff started', $e);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function dispatch(array $events): void
    {
        try {
            $this->bus->dispatch(
                (new SendEvents($events))
                    ->onConnection($this->settings->queueConnection())
                    ->onQueue($this->settings->queueName())
            );
        } catch (Throwable $e) {
            $this->spool->append($events);
            self::debug('Queue dispatch failed; events spooled', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function warning(string $message, array $context = []): void
    {
        try {
            Log::warning('[stats-collector] '.$message, $context);
        } catch (Throwable) {
            // Never let logging break the host site.
        }
    }

    public static function debug(string $message, ?Throwable $e = null): void
    {
        try {
            if (app(Settings::class)->debug()) {
                Log::debug('[stats-collector] '.$message, $e ? ['exception' => $e->getMessage()] : []);
            }
        } catch (Throwable) {
            // Never let logging break the host site.
        }
    }
}
