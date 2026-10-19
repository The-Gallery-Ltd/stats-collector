<?php

declare(strict_types=1);

namespace TheGallery\StatsCollector\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use TheGallery\StatsCollector\EventFactory;
use TheGallery\StatsCollector\Exceptions\TransportException;
use TheGallery\StatsCollector\Settings;
use TheGallery\StatsCollector\Transport\HttpTransport;

final class TestCommand extends Command
{
    public const TEST_PATH = '/__stats-collector-test';

    protected $signature = 'stats:test';

    protected $description = 'Send a synthetic event to the central stats app to verify the configuration';

    public function handle(Settings $settings, EventFactory $factory, HttpTransport $transport): int
    {
        if (! $settings->configured()) {
            $this->error('STATS_ENDPOINT and STATS_KEY must be set.');

            return self::FAILURE;
        }

        $base = rtrim((string) config('app.url', 'http://localhost'), '/') ?: 'http://localhost';
        $request = Request::create($base.self::TEST_PATH, 'GET', server: [
            'HTTP_USER_AGENT' => 'stats-collector/test',
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $event = $factory->make($request, new Response('', 200, ['Content-Type' => 'text/plain']));

        $this->line("Sending test event to {$settings->endpoint()}/api/ingest ...");

        try {
            $transport->send([$event]);
        } catch (TransportException $e) {
            $this->error('Failed: '.$e->getMessage());

            if ($e->isUnauthorized()) {
                $this->line('The central app rejected STATS_KEY.');
            }

            return self::FAILURE;
        }

        $this->info("Success: the central stats app accepted the test event (request_id {$event['request_id']}).");

        return self::SUCCESS;
    }
}
