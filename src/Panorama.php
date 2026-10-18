<?php

namespace Aldeebhasan\Panorama;

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Deliver\DownloadDestination;
use Aldeebhasan\Panorama\Deliver\StorageDestination;
use Aldeebhasan\Panorama\Exceptions\ReportingException;
use Aldeebhasan\Panorama\History\NullRecorder;
use Aldeebhasan\Panorama\History\RecorderContract;
use Aldeebhasan\Panorama\Queue\PendingReport;
use Aldeebhasan\Panorama\Queue\ReportQueue;
use Aldeebhasan\Panorama\Render\RendererRegistry;
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
        private readonly RecorderContract $recorder = new NullRecorder,
        private readonly ?ReportQueue $queue = null,
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

        return new ReportRun(
            $instance,
            $validated,
            $instance->query($this->datasets)->params($validated),
            $this->renderers,
            $this->download,
            $this->storage,
            $this->recorder,
            self::identify($user),
        );
    }

    /**
     * Prepare a queued run of the given report. The parameters are validated
     * and the user authorized now, so a mistake surfaces to the caller rather
     * than in a worker; nothing is queued until the run is dispatched.
     *
     * @param class-string<ReportContract>|string|ReportContract $report
     * @param array<string,mixed> $params
     * @param mixed $user checked now and again in the worker; a model is queued as its identifier
     *
     * @throws ReportingException
     */
    public function queue(string|ReportContract $report, array $params = [], mixed $user = null): PendingReport
    {
        $queue = $this->queue ?? throw ReportingException::queueUnavailable();

        $run = $this->run($report, $params, $user);
        $instance = $run->report();

        return $queue->pending(
            $this->reference($instance),
            $instance->key(),
            $instance->name(),
            $run->params()->all(),
            $user,
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
     * How a worker finds the report again: by key when it is registered, or by
     * class when that class can be resolved. An anonymous class can be neither.
     *
     * @throws ReportingException
     */
    private function reference(ReportContract $report): string
    {
        if ($this->has($report->key())) {
            return $report->key();
        }

        if (str_contains($report::class, '@anonymous')) {
            throw ReportingException::cannotQueue($report->key());
        }

        return $report::class;
    }

    /**
     * The identifier a run is recorded under: the user's auth identifier, or
     * the user itself when an identifier was passed in place of one.
     */
    private static function identify(mixed $user): ?string
    {
        if (is_object($user) && method_exists($user, 'getAuthIdentifier')) {
            $user = $user->getAuthIdentifier();
        }

        return is_int($user) || is_string($user) ? (string) $user : null;
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
