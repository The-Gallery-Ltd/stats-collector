<?php

declare(strict_types=1);

namespace TheGallery\StatsCollector;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;

/**
 * Typed, live view of the stats-collector config (read on every call so
 * runtime config changes are honoured).
 */
final readonly class Settings
{
    public function __construct(
        private Repository $config,
        private Application $app,
    ) {}

    /**
     * Whether request collection should happen at all.
     */
    public function enabled(): bool
    {
        return $this->configured()
            && (bool) $this->get('enabled', true)
            && ! $this->app->runningInConsole();
    }

    /**
     * Whether an endpoint and key are present.
     */
    public function configured(): bool
    {
        return $this->endpoint() !== '' && $this->key() !== '';
    }

    public function endpoint(): string
    {
        return rtrim((string) $this->get('endpoint', ''), '/');
    }

    public function key(): string
    {
        return (string) $this->get('key', '');
    }

    public function driver(): string
    {
        return (string) $this->get('driver', 'sync');
    }

    public function queueConnection(): ?string
    {
        return $this->get('queue.connection') ?: null;
    }

    public function queueName(): ?string
    {
        return $this->get('queue.name') ?: null;
    }

    public function timeout(): float
    {
        return (float) $this->get('timeout', 2);
    }

    public function connectTimeout(): float
    {
        return (float) $this->get('connect_timeout', 1);
    }

    public function captureQuery(): bool
    {
        return (bool) $this->get('capture_query', false);
    }

    public function autoRegisterMiddleware(): bool
    {
        return (bool) $this->get('auto_register_middleware', true);
    }

    /**
     * @param  string  $path  Request path without the leading slash ("/" for the root).
     */
    public function isExcluded(string $path): bool
    {
        $patterns = (array) $this->get('exclude_paths', []);

        return $patterns !== [] && Str::is($patterns, ltrim($path, '/'));
    }

    public function spoolPath(): string
    {
        return rtrim((string) ($this->get('spool.path') ?: $this->app->storagePath('stats-collector')), '/');
    }

    public function spoolMaxBytes(): int
    {
        return (int) $this->get('spool.max_bytes', 50 * 1024 * 1024);
    }

    public function flushBatch(): int
    {
        return max(1, (int) $this->get('spool.flush_batch', 500));
    }

    public function backoffSeconds(): int
    {
        return max(0, (int) $this->get('backoff_seconds', 60));
    }

    public function debug(): bool
    {
        return (bool) $this->get('debug', false);
    }

    private function get(string $key, mixed $default = null): mixed
    {
        return $this->config->get('stats-collector.'.$key, $default);
    }
}
