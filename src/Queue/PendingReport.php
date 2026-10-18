<?php

namespace Aldeebhasan\Panorama\Queue;

use Aldeebhasan\Panorama\Exceptions\RenderException;
use DateInterval;
use DateTimeInterface;

/**
 * A report about to be queued: how to render it, where to store it and whom
 * to tell. Its parameters were validated and its user authorized when it was
 * made, so nothing here can fail for those reasons once dispatched.
 *
 * Nothing is queued until {@see dispatch()}.
 */
final class PendingReport
{
    private string $format = 'csv';
    private ?string $filename = null;
    private ?string $disk = null;
    private ?string $directory = null;

    /** @var list<mixed> */
    private array $notifiables = [];

    private DateTimeInterface|DateInterval|int|null $delay = null;

    /**
     * @param array<string,mixed> $params
     */
    public function __construct(
        private readonly ReportQueue $queue,
        private readonly string $report,
        private readonly string $key,
        private readonly string $name,
        private readonly array $params,
        private readonly mixed $user,
        private ?string $connection = null,
        private ?string $onQueue = null,
    ) {
    }

    /**
     * The format to render, `csv` unless given. Checked now, not in the worker.
     *
     * @throws RenderException
     */
    public function format(string $format): self
    {
        $this->queue->renderers()->get($format);

        $this->format = $format;

        return $this;
    }

    /**
     * Name the stored file, extension included.
     */
    public function named(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    /**
     * Where to store the report, the configured disk and directory unless given.
     */
    public function store(?string $disk = null, ?string $directory = null): self
    {
        $this->disk = $disk;
        $this->directory = $directory;

        return $this;
    }

    /**
     * Who to notify once the report is ready, or once it has failed. Anything
     * notifiable: a model using `Notifiable`, or `Notification::route(...)`.
     */
    public function notify(mixed ...$notifiables): self
    {
        $this->notifiables = [...$this->notifiables, ...array_values($notifiables)];

        return $this;
    }

    public function onConnection(?string $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function onQueue(?string $queue): self
    {
        $this->onQueue = $queue;

        return $this;
    }

    public function delay(DateTimeInterface|DateInterval|int|null $delay): self
    {
        $this->delay = $delay;

        return $this;
    }

    /**
     * The job this would queue, without queueing it.
     */
    public function job(): RunReport
    {
        $job = new RunReport(
            $this->report,
            $this->key,
            $this->name,
            $this->params,
            $this->user,
            $this->format,
            $this->filename,
            $this->disk,
            $this->directory,
            $this->notifiables,
        );

        return $job->onConnection($this->connection)->onQueue($this->onQueue)->delay($this->delay);
    }

    /**
     * Queue the report. Returns whatever the queue returns, usually the job id.
     */
    public function dispatch(): mixed
    {
        return $this->queue->dispatch($this->job());
    }
}
