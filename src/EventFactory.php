<?php

declare(strict_types=1);

namespace Secarma\StatsCollector;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final readonly class EventFactory
{
    public function __construct(
        private Settings $settings,
        private AuthFactory $auth,
    ) {}

    /**
     * Build the ingest event for a handled request.
     *
     * @return array<string, mixed>
     */
    public function make(Request $request, Response $response): array
    {
        $start = $this->startTime($request);
        [$path, $query] = $this->splitUri($request);

        return [
            'request_id' => (string) Str::uuid(),
            'ts' => $this->formatTimestamp($start),
            'method' => $request->getMethod(),
            'scheme' => $request->getScheme(),
            'host' => $this->host($request),
            'path' => $this->truncate($path, 2048),
            'query' => $this->settings->captureQuery() ? $this->truncate($query, 2048) : null,
            'utm' => $this->utm($request),
            'status' => $response->getStatusCode(),
            'duration_ms' => max(0, (int) round((microtime(true) - $start) * 1000)),
            'bytes' => $this->bytes($response),
            'content_type' => $response->headers->get('Content-Type') ?: null,
            'ip' => $request->ip(),
            'user_agent' => $this->truncate($request->userAgent(), 1024),
            'referrer' => $this->truncate($request->headers->get('referer'), 1024),
            'accept_language' => $this->truncate($request->headers->get('accept-language'), 255),
            'route' => $this->routeName($request),
            'authenticated' => $this->authenticated(),
        ];
    }

    private function startTime(Request $request): float
    {
        $start = $request->server('REQUEST_TIME_FLOAT');

        if (is_numeric($start) && (float) $start > 0) {
            return (float) $start;
        }

        return defined('LARAVEL_START') ? (float) LARAVEL_START : microtime(true);
    }

    private function formatTimestamp(float $time): string
    {
        $date = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $time))
            ?: new DateTimeImmutable;

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Raw (as requested) path and query string.
     *
     * @return array{0: string, 1: ?string}
     */
    private function splitUri(Request $request): array
    {
        $parts = explode('?', $request->getRequestUri(), 2);

        $path = $parts[0];
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        $query = ($parts[1] ?? '') !== '' ? $parts[1] : null;

        return [$path, $query];
    }

    /**
     * UTM campaign parameters, captured regardless of capture_query.
     *
     * @return array{source: ?string, medium: ?string, campaign: ?string}|null
     */
    private function utm(Request $request): ?array
    {
        $utm = [];

        foreach (['source', 'medium', 'campaign'] as $key) {
            $value = $request->query('utm_'.$key);
            $utm[$key] = is_string($value) ? $this->truncate($value, 255) : null;
        }

        return array_filter($utm, fn ($v) => $v !== null) === [] ? null : $utm;
    }

    private function host(Request $request): ?string
    {
        try {
            return $request->getHost();
        } catch (Throwable) {
            // Untrusted host header (TrustHosts); fall back to the raw value.
            $raw = $request->server('HTTP_HOST') ?? $request->server('SERVER_NAME');

            return is_string($raw) ? $this->truncate(strtolower($raw), 255) : null;
        }
    }

    private function bytes(Response $response): ?int
    {
        $length = $response->headers->get('Content-Length');
        if (is_numeric($length)) {
            return (int) $length;
        }

        if ($response instanceof StreamedResponse) {
            return null;
        }

        if ($response instanceof BinaryFileResponse) {
            try {
                return $response->getFile()->getSize() ?: null;
            } catch (Throwable) {
                return null;
            }
        }

        $content = $response->getContent();

        return $content === false ? null : strlen($content);
    }

    private function routeName(Request $request): ?string
    {
        try {
            return $request->route()?->getName();
        } catch (Throwable) {
            return null;
        }
    }

    private function authenticated(): bool
    {
        try {
            return $this->auth->guard()->check();
        } catch (Throwable) {
            return false;
        }
    }

    private function truncate(?string $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
