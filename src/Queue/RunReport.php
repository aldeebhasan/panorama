<?php

namespace Aldeebhasan\Panorama\Queue;

use Aldeebhasan\Panorama\Panorama;
use Aldeebhasan\Panorama\Report\ReportRun;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Notifications\Dispatcher as Notifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs one report off the request: renders it, stores it, and tells whoever
 * asked once it is ready, or once its last attempt has failed.
 *
 * The user is authorized again here, not only at dispatch: a permission
 * revoked while the job waited in the queue is honoured.
 *
 * Models among the user and the notifiables are serialized as identifiers and
 * fetched fresh by the worker, the same as any job using `SerializesModels`.
 */
class RunReport implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels {
        getSerializedPropertyValue as private serializeOne;
        getRestoredPropertyValue as private restoreOne;
    }

    /**
     * @param string $report the report's key, or its class when it is not registered
     * @param array<string,mixed> $params
     * @param list<mixed> $notifiables
     * @param DateTimeImmutable|null $stamp stamps the default file name, see {@see ReportRun::stamped()}
     */
    public function __construct(
        public readonly string $report,
        public readonly string $key,
        public readonly string $name,
        public readonly array $params,
        public readonly mixed $user,
        public readonly string $format,
        public readonly ?string $filename,
        public readonly ?string $disk,
        public readonly ?string $directory,
        public readonly array $notifiables = [],
        public readonly ?DateTimeImmutable $stamp = null,
    ) {
    }

    public function handle(Panorama $panorama, ReportQueue $queue, Notifications $notifications): void
    {
        $run = $panorama->run($this->report, $this->params, $this->user);

        if ($this->filename !== null) {
            $run->named($this->filename);
        }

        if ($this->stamp !== null) {
            $run->stamped($this->stamp);
        }

        $rendered = $run->render($this->format);
        $path = $rendered->store($this->disk, $this->directory);

        if ($this->notifiables === []) {
            return;
        }

        $notifications->send($this->notifiables, $queue->ready(new CompletedReport(
            $this->key,
            $this->name,
            $this->format,
            $rendered->filename(),
            $rendered->contentType(),
            $this->disk,
            $path,
            $run->result()->yielded(),
            $rendered->bytes(),
            $run->params()->all(),
        )));
    }

    /**
     * Called by the worker once the last attempt has failed.
     */
    public function failed(?Throwable $failure): void
    {
        if ($this->notifiables === []) {
            return;
        }

        // The worker calls this without injection, so the container is reached
        // for directly. This is the bridge, not the core.
        $container = Container::getInstance();

        $container->make(Notifications::class)->send($this->notifiables, $container->make(ReportQueue::class)->failed(
            $this->key,
            $this->name,
            $this->format,
            $failure === null ? 'The job failed.' : sprintf('%s: %s', $failure::class, $failure->getMessage()),
        ));
    }

    public function displayName(): string
    {
        return sprintf('%s [%s]', self::class, $this->key);
    }

    /**
     * {@see SerializesModels} only looks at a property itself, so a list of
     * notifiable models would be serialized whole. Each one is reduced to its
     * identifier instead.
     */
    protected function getSerializedPropertyValue(mixed $value, bool $withRelations = true): mixed
    {
        return is_array($value)
            ? array_map(fn(mixed $one): mixed => $this->serializeOne($one, $withRelations), $value)
            : $this->serializeOne($value, $withRelations);
    }

    protected function getRestoredPropertyValue(mixed $value): mixed
    {
        return is_array($value)
            ? array_map(fn(mixed $one): mixed => $this->restoreOne($one), $value)
            : $this->restoreOne($value);
    }
}
