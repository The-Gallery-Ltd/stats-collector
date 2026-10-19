<?php

declare(strict_types=1);

namespace TheGallery\StatsCollector;

final class StatsCollector
{
    public const string VERSION = '0.1.0';

    /** Maximum events per POST accepted by the central app. */
    public const int MAX_EVENTS_PER_REQUEST = 500;
}
