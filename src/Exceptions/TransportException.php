<?php

declare(strict_types=1);

namespace TheGallery\StatsCollector\Exceptions;

use RuntimeException;
use Throwable;

final class TransportException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /** The central app rejected the key (misconfiguration; data is kept). */
    public function isUnauthorized(): bool
    {
        return $this->status === 401;
    }

    /** The central app rejected the payload (retrying will never help). */
    public function isInvalidPayload(): bool
    {
        return $this->status === 422;
    }
}
