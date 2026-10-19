<?php

declare(strict_types=1);

namespace TheGallery\StatsCollector\Console;

use Illuminate\Console\Command;
use TheGallery\StatsCollector\Recorder;
use TheGallery\StatsCollector\Settings;
use TheGallery\StatsCollector\Spool;

final class FlushCommand extends Command
{
    protected $signature = 'stats:flush';

    protected $description = 'Send all spooled stats events to the central stats app now';

    public function handle(Settings $settings, Spool $spool, Recorder $recorder): int
    {
        if (! $settings->configured()) {
            $this->error('STATS_ENDPOINT and STATS_KEY must be set.');

            return self::FAILURE;
        }

        $this->line("Spooled events: {$spool->count()}");

        $result = $recorder->flush();

        $this->line("Sent: {$result['sent']}");
        if ($result['dropped'] > 0) {
            $this->warn("Dropped (rejected as invalid): {$result['dropped']}");
        }
        $this->line("Remaining: {$result['remaining']}");

        if ($result['error'] !== null) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        $this->info('Spool flushed.');

        return self::SUCCESS;
    }
}
