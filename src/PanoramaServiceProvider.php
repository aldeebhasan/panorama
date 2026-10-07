<?php

namespace Aldeebhasan\Panorama;

use Aldeebhasan\Panorama\Commands\DatasetDescribeCommand;
use Aldeebhasan\Panorama\Commands\DatasetListCommand;
use Aldeebhasan\Panorama\Commands\MakeDatasetCommand;
use Aldeebhasan\Panorama\Commands\MakeReportCommand;
use Aldeebhasan\Panorama\Commands\ReportListCommand;
use Aldeebhasan\Panorama\Commands\ReportRunCommand;
use Aldeebhasan\Panorama\Connection\ConnectionRegistry;
use Aldeebhasan\Panorama\Dataset\DatasetRegistry;
use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Deliver\DownloadDestination;
use Aldeebhasan\Panorama\Deliver\StorageDestination;
use Aldeebhasan\Panorama\Query\Planner;
use Aldeebhasan\Panorama\Render\CsvRenderer;
use Aldeebhasan\Panorama\Render\JsonRenderer;
use Aldeebhasan\Panorama\Render\RendererRegistry;
use Aldeebhasan\Panorama\Support\ParameterValidator;
use Aldeebhasan\Panorama\Workspace\MergeRunner;
use Aldeebhasan\Panorama\Workspace\WorkspaceFactory;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
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

        $this->registerSources();
        $this->registerWorkspaces();
        $this->registerReporting();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReportListCommand::class,
                ReportRunCommand::class,
                MakeReportCommand::class,
                DatasetListCommand::class,
                DatasetDescribeCommand::class,
                MakeDatasetCommand::class,
            ]);
        }
    }

    /**
     * Connections and datasets: where the data is and what shape it has.
     */
    private function registerSources(): void
    {
        $this->app->singleton(ConnectionRegistry::class, fn(Application $app) => new ConnectionRegistry(
            $this->section('panorama.connections'),
            $app->make(ConnectionResolverInterface::class),
            $app->make(HttpFactory::class),
        ));

        $this->app->singleton(DatasetRegistry::class, fn(Application $app) => $this->datasets(
            new DatasetRegistry($app->make(ConnectionRegistry::class), $app),
        ));

        $this->app->singleton(ParameterValidator::class, fn(Application $app) => new ParameterValidator(
            $app->make(ValidationFactory::class),
        ));
    }

    /**
     * The engine datasets are merged in.
     */
    private function registerWorkspaces(): void
    {
        $this->app->singleton(Planner::class, fn(Application $app) => new Planner(
            $app->make(DatasetRegistry::class),
        ));

        $this->app->singleton(WorkspaceFactory::class, fn(Application $app) => new WorkspaceFactory(
            $this->section('panorama.workspaces'),
            $app->make(ConnectionResolverInterface::class),
            $this->string('panorama.workspace', 'database'),
        ));

        $this->app->singleton(MergeRunner::class, fn(Application $app) => new MergeRunner(
            $app->make(Planner::class),
            $app->make(WorkspaceFactory::class),
        ));

        $this->app->singleton(Datasets::class, fn(Application $app) => new Datasets(
            $app->make(DatasetRegistry::class),
            $app->make(MergeRunner::class),
        ));
    }

    /**
     * Reports, the formats they render to and the places they go.
     */
    private function registerReporting(): void
    {
        $this->app->singleton(RendererRegistry::class, fn() => new RendererRegistry([
            new CsvRenderer,
            new JsonRenderer,
        ]));

        $this->app->singleton(DownloadDestination::class, fn() => new DownloadDestination);

        $this->app->singleton(StorageDestination::class, fn(Application $app) => new StorageDestination(
            $app->make(FilesystemFactory::class),
            $this->nullableString('panorama.storage.disk'),
            $this->string('panorama.storage.path', 'reports'),
        ));

        // Bound rather than shared: it holds a container reference, and under
        // Octane a singleton would outlive the request that built it.
        $this->app->bind(Panorama::class, fn(Application $app) => $this->reports(new Panorama(
            $app,
            $app->make(Datasets::class),
            $app->make(ParameterValidator::class),
            $app->make(RendererRegistry::class),
            $app->make(DownloadDestination::class),
            $app->make(StorageDestination::class),
        )));
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

    private function reports(Panorama $panorama): Panorama
    {
        foreach ($this->section('panorama.reports') as $report) {
            if (is_string($report)) {
                $panorama->register($report);
            }
        }

        return $panorama;
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

    private function string(string $key, string $default): string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function nullableString(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
