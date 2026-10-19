<?php

declare(strict_types=1);

namespace TheGallery\StatsCollector;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;
use TheGallery\StatsCollector\Console\FlushCommand;
use TheGallery\StatsCollector\Console\TestCommand;
use TheGallery\StatsCollector\Http\Middleware\CollectRequest;
use TheGallery\StatsCollector\Transport\HttpTransport;
use Throwable;

final class StatsCollectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/stats-collector.php', 'stats-collector');

        $this->app->singleton(Settings::class);
        $this->app->singleton(Spool::class);
        $this->app->singleton(Backoff::class);
        $this->app->singleton(EventFactory::class);
        $this->app->singleton(HttpTransport::class);
        $this->app->singleton(Recorder::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/stats-collector.php' => $this->app->configPath('stats-collector.php'),
        ], 'stats-collector-config');

        $this->commands([
            FlushCommand::class,
            TestCommand::class,
        ]);

        $this->registerMiddleware();
    }

    private function registerMiddleware(): void
    {
        try {
            if (! $this->app->make(Settings::class)->autoRegisterMiddleware()) {
                return;
            }

            $kernel = $this->app->make(HttpKernel::class);

            if (method_exists($kernel, 'hasMiddleware') && $kernel->hasMiddleware(CollectRequest::class)) {
                return;
            }

            $kernel->pushMiddleware(CollectRequest::class);
        } catch (Throwable $e) {
            Recorder::debug('Could not register middleware', $e);
        }
    }
}
