<?php

declare(strict_types=1);

namespace Secarma\StatsCollector;

/**
 * Local JSONL spool for events that could not be delivered yet.
 *
 * Layout inside the spool directory:
 *   spool.jsonl          events appended by requests (LOCK_EX per append)
 *   spool.sending        events claimed by the current/last flusher
 *   spool.sending.offset byte offset into spool.sending already delivered
 *   flush.lock           held (non-blocking) by whoever is flushing
 *
 * Flushing never edits spool.jsonl in place: while holding the flush lock
 * (and briefly the spool's own lock) the flusher renames it to
 * spool.sending, so concurrent appends start a fresh spool.jsonl and are
 * never lost. Delivered progress through spool.sending is tracked as a byte
 * offset that is only advanced after a successful send, so a crash or a
 * failed send just means those events are sent again (the central app
 * de-duplicates on request_id).
 */
final class Spool
{
    private const string SPOOL = 'spool.jsonl';

    private const string SENDING = 'spool.sending';

    private const string OFFSET = 'spool.sending.offset';

    private const string LOCK = 'flush.lock';

    /** @var resource|null */
    private $lockHandle = null;

    /** Offset in spool.sending just past the events returned by claim(). */
    private ?int $claimedOffset = null;

    public function __construct(private readonly Settings $settings) {}

    /**
     * Append events to the spool. Returns how many were written (0 if the
     * spool is full or unwritable).
     *
     * @param  list<array<string, mixed>>  $events
     */
    public function append(array $events): int
    {
        if ($events === [] || ! $this->ensureDirectory()) {
            return 0;
        }

        $payload = '';
        foreach ($events as $event) {
            $payload .= json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
        }

        $path = $this->path(self::SPOOL);

        // A flusher may rename the file between our fopen() and flock();
        // if so, reopen so we write to the live spool file.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $handle = @fopen($path, 'ab');
            if ($handle === false) {
                return 0;
            }

            try {
                if (! flock($handle, LOCK_EX)) {
                    return 0;
                }

                clearstatcache(true, $path);
                $current = @stat($path);
                $mine = fstat($handle);

                if ($current === false || $mine === false || $current['ino'] !== $mine['ino']) {
                    continue;
                }

                if ($this->settings->spoolMaxBytes() < $mine['size'] + $this->size(self::SENDING) + strlen($payload)) {
                    return 0;
                }

                fwrite($handle, $payload);
                fflush($handle);

                return count($events);
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        return 0;
    }

    /**
     * Try to take the flush lock without blocking.
     */
    public function tryLock(): bool
    {
        if ($this->lockHandle !== null) {
            return true;
        }

        if (! $this->ensureDirectory()) {
            return false;
        }

        $handle = @fopen($this->path(self::LOCK), 'c');
        if ($handle === false) {
            return false;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->lockHandle = $handle;

        return true;
    }

    public function unlock(): void
    {
        $this->claimedOffset = null;

        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /**
     * Read up to $limit spooled events for sending. Requires the flush lock.
     * Call ack() after a successful send; otherwise the same events are
     * returned again next time.
     *
     * @return list<array<string, mixed>>
     */
    public function claim(int $limit): array
    {
        $this->claimedOffset = null;

        if ($this->lockHandle === null || $limit < 1) {
            return [];
        }

        $sending = $this->path(self::SENDING);
        $offset = $this->readOffset();

        clearstatcache(true, $sending);
        if (! is_file($sending) || $offset >= (int) @filesize($sending)) {
            $this->discardSending();
            $this->rotate();
            $offset = 0;
        }

        if (! is_file($sending)) {
            return [];
        }

        $handle = @fopen($sending, 'rb');
        if ($handle === false) {
            return [];
        }

        $events = [];

        try {
            fseek($handle, $offset);

            while (count($events) < $limit && ($line = fgets($handle)) !== false) {
                $event = json_decode(trim($line), true);

                if (is_array($event)) {
                    $events[] = $event;
                }
            }

            $this->claimedOffset = (int) ftell($handle);
        } finally {
            fclose($handle);
        }

        return $events;
    }

    /**
     * Mark the events returned by the last claim() as delivered.
     */
    public function ack(): void
    {
        if ($this->lockHandle === null || $this->claimedOffset === null) {
            return;
        }

        $sending = $this->path(self::SENDING);
        clearstatcache(true, $sending);

        if ($this->claimedOffset >= (int) @filesize($sending)) {
            $this->discardSending();
        } else {
            @file_put_contents($this->path(self::OFFSET), (string) $this->claimedOffset, LOCK_EX);
        }

        $this->claimedOffset = null;
    }

    /**
     * Number of undelivered events currently spooled.
     */
    public function count(): int
    {
        $count = 0;

        foreach ([self::SENDING => $this->readOffset(), self::SPOOL => 0] as $file => $offset) {
            $handle = @fopen($this->path($file), 'rb');
            if ($handle === false) {
                continue;
            }

            fseek($handle, $offset);
            while (($line = fgets($handle)) !== false) {
                if (trim($line) !== '') {
                    $count++;
                }
            }
            fclose($handle);
        }

        return $count;
    }

    public function directory(): string
    {
        return $this->settings->spoolPath();
    }

    public function path(string $file): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.$file;
    }

    public function ensureDirectory(): bool
    {
        $dir = $this->directory();

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return false;
        }

        $gitignore = $dir.DIRECTORY_SEPARATOR.'.gitignore';
        if (! is_file($gitignore)) {
            @file_put_contents($gitignore, "*\n!.gitignore\n");
        }

        return true;
    }

    /**
     * Move spool.jsonl to spool.sending, holding the spool's append lock so
     * no write is in flight during the rename.
     */
    private function rotate(): void
    {
        $spool = $this->path(self::SPOOL);

        if (! is_file($spool)) {
            return;
        }

        $handle = @fopen($spool, 'rb');
        if ($handle === false) {
            return;
        }

        try {
            if (flock($handle, LOCK_EX)) {
                @rename($spool, $this->path(self::SENDING));
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function discardSending(): void
    {
        @unlink($this->path(self::SENDING));
        @unlink($this->path(self::OFFSET));
    }

    private function readOffset(): int
    {
        $raw = @file_get_contents($this->path(self::OFFSET));

        return $raw === false ? 0 : max(0, (int) $raw);
    }

    private function size(string $file): int
    {
        $path = $this->path($file);
        clearstatcache(true, $path);

        return is_file($path) ? (int) @filesize($path) : 0;
    }
}
