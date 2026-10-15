<?php

namespace Aldeebhasan\Panorama;

use Aldeebhasan\Panorama\Commands\DatasetDescribeCommand;
use Aldeebhasan\Panorama\Commands\DatasetListCommand;
use Aldeebhasan\Panorama\Commands\MakeDatasetCommand;
use Aldeebhasan\Panorama\Commands\MakeReportCommand;
use Aldeebhasan\Panorama\Commands\ReportListCommand;
use Aldeebhasan\Panorama\Commands\ReportRunCommand;
use Aldeebhasan\Panorama\Dataset\DatasetRegistry;
use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Deliver\DownloadDestination;
use Aldeebhasan\Panorama\Deliver\StorageDestination;
use Aldeebhasan\Panorama\Query\Executor;
use Aldeebhasan\Panorama\Render\CsvRenderer;
use Aldeebhasan\Panorama\Render\HtmlRenderer;
use Aldeebhasan\Panorama\Render\JsonRenderer;
use Aldeebhasan\Panorama\Render\RendererRegistry;
use Aldeebhasan\Panorama\Support\ParameterValidator;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
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

        $this->registerDatasets();
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
     * Datasets, and the queries reports build over them.
     */
    private function registerDatasets(): void
    {
        // Scoped rather than shared: the registry holds a container reference,
        // and under Octane a singleton would outlive the request that built it.
        // Everything holding the registry is scoped with it.
        $this->app->scoped(DatasetRegistry::class, fn(Application $app) => $this->datasets(
            new DatasetRegistry($app),
        ));

        $this->app->scoped(Executor::class, fn(Application $app) => new Executor(
            $app->make(DatasetRegistry::class),
        ));

        $this->app->scoped(Datasets::class, fn(Application $app) => new Datasets(
            $app->make(DatasetRegistry::class),
            $app->make(Executor::class),
        ));

        $this->app->singleton(ParameterValidator::class, fn(Application $app) => new ParameterValidator(
            $app->make(ValidationFactory::class),
        ));
    }

    /**
     * Reports, the formats they render to and the places they go.
     */
    private function registerReporting(): void
    {
        $this->app->singleton(RendererRegistry::class, fn() => new RendererRegistry([
            new CsvRenderer,
            new HtmlRenderer,
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
