<?php

namespace Aldeebhasan\Panorama;

use Aldeebhasan\Panorama\Commands\DatasetDescribeCommand;
use Aldeebhasan\Panorama\Commands\DatasetListCommand;
use Aldeebhasan\Panorama\Commands\MakeDatasetCommand;
use Aldeebhasan\Panorama\Commands\ReportGenerateCommand;
use Aldeebhasan\Panorama\Commands\ReportListCommand;
use Aldeebhasan\Panorama\Connection\ConnectionRegistry;
use Aldeebhasan\Panorama\Dataset\DatasetRegistry;
use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Query\Planner;
use Aldeebhasan\Panorama\Support\ParameterValidator;
use Aldeebhasan\Panorama\Workspace\MergeRunner;
use Aldeebhasan\Panorama\Workspace\WorkspaceFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
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

        $this->app->singleton(ConnectionRegistry::class, fn(Application $app) => new ConnectionRegistry(
            $this->section('panorama.connections'),
            $app->make(ConnectionResolverInterface::class),
            $app->make(HttpFactory::class),
        ));

        $this->app->singleton(DatasetRegistry::class, fn(Application $app) => $this->datasets(
            new DatasetRegistry($app->make(ConnectionRegistry::class), $app),
        ));

        $this->app->singleton(Planner::class, fn(Application $app) => new Planner(
            $app->make(DatasetRegistry::class),
        ));

        $this->app->singleton(WorkspaceFactory::class, fn(Application $app) => new WorkspaceFactory(
            $this->section('panorama.workspaces'),
            $app->make(ConnectionResolverInterface::class),
            is_string($engine = config('panorama.workspace')) ? $engine : 'database',
        ));

        $this->app->singleton(MergeRunner::class, fn(Application $app) => new MergeRunner(
            $app->make(Planner::class),
            $app->make(WorkspaceFactory::class),
        ));

        $this->app->singleton(Datasets::class, fn(Application $app) => new Datasets(
            $app->make(DatasetRegistry::class),
            $app->make(MergeRunner::class),
        ));

        $this->app->singleton(ParameterValidator::class, fn(Application $app) => new ParameterValidator(
            $app->make(ValidationFactory::class),
        ));

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReportListCommand::class,
                ReportGenerateCommand::class,
                DatasetListCommand::class,
                DatasetDescribeCommand::class,
                MakeDatasetCommand::class,
            ]);
        }
    }

    /**
     * Load the datasets declared in config, listed and discovered.
     */
    private function datasets(DatasetRegistry $registry): DatasetRegistry
    {
        foreach ($this->section('panorama.datasets.register') as $dataset) {
            if (is_string($dataset)) {
                $registry->register($dataset);
            }
        }

        foreach ($this->section('panorama.datasets.discover') as $path => $namespace) {
            if (is_string($path) && is_string($namespace)) {
                $registry->discover($path, $namespace);
            }
        }

        return $registry;
    }

    /**
     * Read an array out of the config without letting `mixed` leak onward.
     *
     * @return array<array-key,mixed>
     */
    private function section(string $key): array
    {
        $value = config($key, []);

        return is_array($value) ? $value : [];
    }
}
