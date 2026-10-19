<?php

declare(strict_types=1);

namespace TheGallery\StatsCollector;

use Illuminate\Support\Facades\Date;

/**
 * File-backed "don't hit the network until T" marker, shared by all PHP
 * workers on the host (no cache store required).
 */
final readonly class Backoff
{
    private const FILE = 'backoff-until';

    public function __construct(
        private Spool $spool,
        private Settings $settings,
    ) {}

    public function active(): bool
    {
        return $this->until() > Date::now()->getTimestamp();
    }

    /** Unix timestamp the current backoff window ends at (0 if none). */
    public function until(): int
    {
        $raw = @file_get_contents($this->spool->path(self::FILE));

        return $raw === false ? 0 : (int) $raw;
    }

    public function start(): void
    {
        $seconds = $this->settings->backoffSeconds();

        if ($seconds > 0 && $this->spool->ensureDirectory()) {
            @file_put_contents(
                $this->spool->path(self::FILE),
                (string) (Date::now()->getTimestamp() + $seconds),
                LOCK_EX,
            );
        }
    }

    public function clear(): void
    {
        $path = $this->spool->path(self::FILE);

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
