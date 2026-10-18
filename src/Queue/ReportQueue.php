<?php

namespace Aldeebhasan\Panorama\Queue;

use Aldeebhasan\Panorama\Notifications\ReportFailed;
use Aldeebhasan\Panorama\Notifications\ReportReady;
use Aldeebhasan\Panorama\Render\RendererRegistry;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * How queued reports are dispatched and what is sent when they finish, as
 * configured under `panorama.queue`.
 */
final class ReportQueue
{
    /**
     * @param list<string> $channels
     * @param class-string<ReportReady> $ready
     * @param class-string<ReportFailed> $failed
     */
    public function __construct(
        private readonly Dispatcher $bus,
        private readonly RendererRegistry $renderers,
        private readonly ?string $connection = null,
        private readonly ?string $queue = null,
        private readonly array $channels = ['mail'],
        private readonly int $attachUpTo = 10 * 1024 * 1024,
        private readonly string $ready = ReportReady::class,
        private readonly string $failed = ReportFailed::class,
    ) {
    }

    /**
     * @param array<string,mixed> $params
     */
    public function pending(string $report, string $key, string $name, array $params, mixed $user): PendingReport
    {
        return new PendingReport($this, $report, $key, $name, $params, $user, $this->connection, $this->queue);
    }

    public function dispatch(RunReport $job): mixed
    {
        return $this->bus->dispatch($job);
    }

    public function renderers(): RendererRegistry
    {
        return $this->renderers;
    }

    public function ready(CompletedReport $report): ReportReady
    {
        return new ($this->ready)($report, $this->channels, $this->attachUpTo);
    }

    public function failed(string $key, string $name, string $format, string $error): ReportFailed
    {
        return new ($this->failed)($key, $name, $format, $error, $this->channels);
    }
}
