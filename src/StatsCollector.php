<?php

declare(strict_types=1);

namespace TheGallery\StatsCollector;

final class StatsCollector
{
    public const VERSION = '0.2.1';

    /** Maximum events per POST accepted by the central app. */
    public const MAX_EVENTS_PER_REQUEST = 500;
}
