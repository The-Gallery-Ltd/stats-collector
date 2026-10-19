<?php

declare(strict_types=1);

namespace TheGallery\StatsCollector\Transport;

use Illuminate\Http\Client\Factory as HttpFactory;
use TheGallery\StatsCollector\Exceptions\TransportException;
use TheGallery\StatsCollector\Settings;
use TheGallery\StatsCollector\StatsCollector;
use Throwable;

final readonly class HttpTransport
{
    public function __construct(
        private HttpFactory $http,
        private Settings $settings,
    ) {}

    /**
     * Send events to the central app, in chunks of at most 500.
     *
     * @param  list<array<string, mixed>>  $events
     *
     * @throws TransportException
     */
    public function send(array $events): void
    {
        if ($events === []) {
            return;
        }

        if (! $this->settings->configured()) {
            throw new TransportException('Stats collector endpoint or key is not configured.');
        }

        foreach (array_chunk($events, StatsCollector::MAX_EVENTS_PER_REQUEST) as $chunk) {
            $this->post($chunk);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function post(array $events): void
    {
        $url = $this->settings->endpoint().'/api/ingest';

        try {
            $response = $this->http
                ->withToken($this->settings->key())
                ->acceptJson()
                ->asJson()
                ->withUserAgent('stats-collector/'.StatsCollector::VERSION)
                ->timeout($this->settings->timeout())
                ->connectTimeout($this->settings->connectTimeout())
                ->post($url, [
                    'collector' => ['version' => StatsCollector::VERSION],
                    'events' => array_values($events),
                ]);
        } catch (Throwable $e) {
            throw new TransportException("Request to {$url} failed: {$e->getMessage()}", null, $e);
        }

        if (! $response->successful()) {
            throw new TransportException(
                "Central stats app responded with HTTP {$response->status()}.",
                $response->status(),
            );
        }
    }
}
