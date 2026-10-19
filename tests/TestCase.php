<?php

declare(strict_types=1);

namespace Secarma\StatsCollector\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as Orchestra;
use Secarma\StatsCollector\StatsCollectorServiceProvider;

abstract class TestCase extends Orchestra
{
    protected string $spoolPath;

    protected function getPackageProviders($app): array
    {
        return [StatsCollectorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->spoolPath = sys_get_temp_dir().'/stats-collector-tests-'.bin2hex(random_bytes(6));

        $app['config']->set('stats-collector.endpoint', 'https://stats.test/');
        $app['config']->set('stats-collector.key', 'test-key');
        $app['config']->set('stats-collector.spool.path', $this->spoolPath);
        $app['config']->set('app.url', 'https://site.test');
    }

    protected function defineRoutes($router): void
    {
        Route::get('/hello', fn () => 'hello world')->name('hello');
        Route::get('/up', fn () => 'ok');
        Route::get('/livewire/update', fn () => 'lw');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->spoolPath);

        parent::tearDown();
    }
}
