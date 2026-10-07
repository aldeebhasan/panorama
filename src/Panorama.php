<?php

namespace Aldeebhasan\Panorama;

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Deliver\DownloadDestination;
use Aldeebhasan\Panorama\Deliver\StorageDestination;
use Aldeebhasan\Panorama\Exceptions\ReportingException;
use Aldeebhasan\Panorama\Render\RendererRegistry;
use Aldeebhasan\Panorama\Report\Report;
use Aldeebhasan\Panorama\Report\ReportContract;
use Aldeebhasan\Panorama\Report\ReportRun;
use Aldeebhasan\Panorama\Support\ParameterValidator;
use Psr\Container\ContainerInterface;

/**
 * Where reports are registered and run.
 *
 * Running a report validates its parameters, checks authorization when a user
 * is given, and hands back a {@see ReportRun} that has not executed anything
 * yet. The format and the destination are chosen on the run.
 */
class Panorama
{
    /** @var array<string,string|ReportContract> */
    private array $reports = [];

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Datasets $datasets,
        private readonly ParameterValidator $validator,
        private readonly RendererRegistry $renderers,
        private readonly DownloadDestination $download,
        private readonly StorageDestination $storage,
    ) {
    }

    /**
     * Register a report, as a class name or a ready made instance. Class names
     * are validated when they are resolved, not when they are given.
     *
     * @param class-string<ReportContract>|string|ReportContract $report
     *
     * @throws ReportingException
     */
    public function register(string|ReportContract $report): self
    {
        $instance = is_string($report) ? $this->resolve($report) : $report;

        $this->reports[$instance->key()] = $report;

        return $this;
    }

    /**
     * A fresh instance of the named report.
     *
     * @throws ReportingException
     */
    public function report(string $key): ReportContract
    {
        $report = $this->reports[$key] ?? throw ReportingException::reportNotFound($key, $this->keys());

        return is_string($report) ? $this->resolve($report) : clone $report;
    }

    /**
     * Prepare a run of the given report.
     *
     * @param class-string<ReportContract>|string|ReportContract $report
     * @param array<string,mixed> $params
     * @param mixed $user checked against the report's own authorize(), when given
     *
     * @throws ReportingException
     */
    public function run(string|ReportContract $report, array $params = [], mixed $user = null): ReportRun
    {
        $instance = is_string($report) && !$this->has($report) && class_exists($report)
            ? $this->resolve($report)
            : (is_string($report) ? $this->report($report) : $report);

        if ($user !== null && !$instance->authorize($user)) {
            throw ReportingException::unauthorized($instance->key());
        }

        $validated = $this->validator->validate(Params::make($params), $instance->parameters());

        $merge = $instance->query($this->datasets)->params($validated);

        $engine = $instance instanceof Report ? $instance->engine() : null;
        if ($engine !== null) {
            $merge->on($engine);
        }

        return new ReportRun(
            $instance,
            $validated,
            $merge,
            $this->renderers,
            $this->download,
            $this->storage,
        );
    }

    public function has(string $key): bool
    {
        return isset($this->reports[$key]);
    }

    /**
     * The registered report keys.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->reports);
    }

    /**
     * The registered reports, keyed by key and mapped to their names.
     *
     * @return array<string,string>
     *
     * @throws ReportingException
     */
    public function all(): array
    {
        $reports = [];
        foreach ($this->keys() as $key) {
            $reports[$key] = $this->report($key)->name();
        }

        return $reports;
    }

    /**
     * @throws ReportingException
     */
    private function resolve(string $report): ReportContract
    {
        $instance = class_exists($report) ? $this->container->get($report) : null;

        if (!$instance instanceof ReportContract) {
            throw ReportingException::invalidReport($report);
        }

        return $instance;
    }
}
