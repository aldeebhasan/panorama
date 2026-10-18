<?php

namespace Aldeebhasan\Panorama;

use Aldeebhasan\Panorama\Commands\DatasetDescribeCommand;
use Aldeebhasan\Panorama\Commands\DatasetListCommand;
use Aldeebhasan\Panorama\Commands\MakeDatasetCommand;
use Aldeebhasan\Panorama\Commands\MakeReportCommand;
use Aldeebhasan\Panorama\Commands\ReportHistoryCommand;
use Aldeebhasan\Panorama\Commands\ReportListCommand;
use Aldeebhasan\Panorama\Commands\ReportPruneCommand;
use Aldeebhasan\Panorama\Commands\ReportRunCommand;
use Aldeebhasan\Panorama\Dataset\DatasetRegistry;
use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Deliver\DownloadDestination;
use Aldeebhasan\Panorama\Deliver\StorageDestination;
use Aldeebhasan\Panorama\History\DatabaseRecorder;
use Aldeebhasan\Panorama\History\NullRecorder;
use Aldeebhasan\Panorama\History\RecordedRun;
use Aldeebhasan\Panorama\History\RecorderContract;
use Aldeebhasan\Panorama\Notifications\ReportFailed;
use Aldeebhasan\Panorama\Notifications\ReportReady;
use Aldeebhasan\Panorama\Query\Executor;
use Aldeebhasan\Panorama\Queue\ReportQueue;
use Aldeebhasan\Panorama\Render\CsvRenderer;
use Aldeebhasan\Panorama\Render\HtmlRenderer;
use Aldeebhasan\Panorama\Render\JsonRenderer;
use Aldeebhasan\Panorama\Render\RendererRegistry;
use Aldeebhasan\Panorama\Render\XlsxRenderer;
use Aldeebhasan\Panorama\Support\ParameterValidator;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\ConnectionResolverInterface;
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

        $this->publishesMigrations([
            __DIR__ . '/../database/migrations/create_report_runs_table.php.stub' => database_path(
                'migrations/' . date('Y_m_d_His') . '_create_report_runs_table.php',
            ),
        ], 'panorama-migrations');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/panorama.php', 'panorama');

        $this->registerDatasets();
        $this->registerHistory();
        $this->registerReporting();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReportListCommand::class,
                ReportRunCommand::class,
                ReportHistoryCommand::class,
                ReportPruneCommand::class,
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
     * Where runs are recorded, when they are.
     */
    private function registerHistory(): void
    {
        RecordedRun::configure(
            $this->string('panorama.history.table', 'report_runs'),
            $this->nullableString('panorama.history.connection'),
            $this->positiveInt('panorama.history.keep_days', 90),
        );

        $this->app->singleton(RecorderContract::class, fn(Application $app) => $this->historyEnabled()
            ? new DatabaseRecorder(
                $app->make(ConnectionResolverInterface::class),
                $this->string('panorama.history.table', 'report_runs'),
                $this->nullableString('panorama.history.connection'),
            )
            : new NullRecorder);
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
            new XlsxRenderer,
        ]));

        $this->app->singleton(DownloadDestination::class, fn() => new DownloadDestination);

        $this->app->singleton(StorageDestination::class, fn(Application $app) => new StorageDestination(
            $app->make(FilesystemFactory::class),
            $this->nullableString('panorama.storage.disk'),
            $this->string('panorama.storage.path', 'reports'),
        ));

        $this->app->singleton(ReportQueue::class, fn(Application $app) => new ReportQueue(
            $app->make(Dispatcher::class),
            $app->make(RendererRegistry::class),
            $this->nullableString('panorama.queue.connection'),
            $this->nullableString('panorama.queue.queue'),
            $this->strings('panorama.queue.channels', ['mail']),
            $this->positiveInt('panorama.queue.attach_up_to', 10 * 1024 * 1024, allowZero: true),
            $this->subclass('panorama.queue.notifications.ready', ReportReady::class),
            $this->subclass('panorama.queue.notifications.failed', ReportFailed::class),
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
            $app->make(RecorderContract::class),
            $app->make(ReportQueue::class),
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

    private function historyEnabled(): bool
    {
        return filter_var(config('panorama.history.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function positiveInt(string $key, int $default, bool $allowZero = false): int
    {
        $value = filter_var(config($key), FILTER_VALIDATE_INT);

        return is_int($value) && ($value > 0 || ($allowZero && $value === 0)) ? $value : $default;
    }

    /**
     * @param list<string> $default
     * @return list<string>
     */
    private function strings(string $key, array $default): array
    {
        $values = array_values(array_filter(
            $this->section($key),
            static fn(mixed $value): bool => is_string($value) && $value !== '',
        ));

        return $values === [] ? $default : $values;
    }

    /**
     * A configured class, kept only when it extends the one the package expects.
     *
     * @template T of object
     *
     * @param class-string<T> $default
     * @return class-string<T>
     */
    private function subclass(string $key, string $default): string
    {
        $value = config($key);

        return is_string($value) && is_a($value, $default, true) ? $value : $default;
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
