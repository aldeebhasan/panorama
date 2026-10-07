<?php

namespace Aldeebhasan\Panorama;

use Aldeebhasan\Panorama\Commands\ReportGenerateCommand;
use Aldeebhasan\Panorama\Commands\ReportListCommand;
use Illuminate\Support\ServiceProvider;

class PanoramaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/panorama.php' => config_path('panorama.php'),
        ], 'panorama-config');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'panorama');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/panorama'),
        ], 'panorama-views');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/panorama.php', 'panorama');

        $this->app->singleton(Panorama::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReportListCommand::class,
                ReportGenerateCommand::class,
            ]);
        }
    }
}
