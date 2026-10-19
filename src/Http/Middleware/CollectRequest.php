<?php

declare(strict_types=1);

namespace TheGallery\StatsCollector\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use TheGallery\StatsCollector\EventFactory;
use TheGallery\StatsCollector\Recorder;
use TheGallery\StatsCollector\Settings;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Terminable middleware: does nothing during the request, then records the
 * request in terminate(), which under PHP-FPM runs after the response has
 * already been flushed to the visitor (fastcgi_finish_request()).
 */
final readonly class CollectRequest
{
    public function __construct(
        private Settings $settings,
        private EventFactory $events,
        private Recorder $recorder,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }

    public function terminate(Request $request, mixed $response): void
    {
        try {
            if (! $response instanceof Response
                || ! $this->settings->enabled()
                || $this->settings->isExcluded($request->path())) {
                return;
            }

            $this->recorder->record($this->events->make($request, $response));
        } catch (Throwable $e) {
            Recorder::debug('Failed to collect request', $e);
        }
    }
}
