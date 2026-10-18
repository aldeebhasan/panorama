<?php

namespace Aldeebhasan\Panorama\Scheduling;

use Aldeebhasan\Panorama\Exceptions\RenderException;
use Aldeebhasan\Panorama\Panorama;
use Aldeebhasan\Panorama\Queue\ReportQueue;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Notifications\Dispatcher as Notifications;
use Throwable;

/**
 * A report queued on a schedule.
 *
 * It is registered on Laravel's scheduler the moment it is made, so it shows
 * up in `schedule:list` and takes every frequency and constraint a scheduled
 * event takes: `dailyAt()`, `monthlyOn()`, `timezone()`, `onOneServer()`,
 * `environments()` and the rest are passed through to the event.
 *
 * Each time it fires, the parameters and the notifiables are worked out anew,
 * and the report is queued exactly as {@see Panorama::queue()} would. Scheduled
 * runs have no user, so a report's `authorize()` is not consulted.
 *
 * @mixin CallbackEvent
 */
final class ScheduledReport
{
    private CallbackEvent $event;

    /** @var array<string,mixed>|Closure(CarbonImmutable):array<string,mixed> */
    private array|Closure $params = [];

    private string $format = 'csv';

    /** @var string|Closure(CarbonImmutable):string|null */
    private string|Closure|null $filename = null;

    private ?string $disk = null;
    private ?string $directory = null;
    private ?string $connection = null;
    private ?string $queue = null;

    /** @var list<mixed> */
    private array $notifiables = [];

    public function __construct(
        Schedule $schedule,
        private readonly ReportQueue $reports,
        private readonly string $report,
        private readonly string $key,
        private readonly string $name,
    ) {
        // Named, so it reads as itself in `schedule:list` and so that
        // onOneServer() and withoutOverlapping() have a mutex to hold.
        $this->event = $schedule->call($this)->name(sprintf('Report [%s]', $key));
    }

    /**
     * The parameters to run with: an array, or a closure given the moment the
     * schedule fired, in its timezone, for ranges like "last month".
     *
     * @param array<string,mixed>|Closure(CarbonImmutable):array<string,mixed> $params
     */
    public function params(array|Closure $params): self
    {
        $this->params = $params;

        return $this;
    }

    /**
     * The format to render, `csv` unless given. Checked now, not when it fires.
     *
     * @throws RenderException
     */
    public function format(string $format): self
    {
        $this->reports->renderers()->get($format);

        $this->format = $format;

        return $this;
    }

    /**
     * Name the stored file, as a string or a closure given the moment the
     * schedule fired. Without one, the default name is stamped with that
     * moment so each run keeps its own file.
     *
     * @param string|Closure(CarbonImmutable):string $filename
     */
    public function named(string|Closure $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    public function store(?string $disk = null, ?string $directory = null): self
    {
        $this->disk = $disk;
        $this->directory = $directory;

        return $this;
    }

    /**
     * Who to notify each time. Anything notifiable, a list of them, or a
     * closure returning either, called when the schedule fires so the list is
     * never stale.
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
        $this->queue = $queue;

        return $this;
    }

    /**
     * The scheduled event underneath, for anything not passed through.
     */
    public function event(): CallbackEvent
    {
        return $this->event;
    }

    /**
     * Called by the scheduler: queue one run of the report.
     *
     * A run that cannot even be queued, its parameters no longer valid for
     * instance, is announced as failed to the same people before the
     * scheduler records the failure. Once queued, the job does its own telling.
     */
    public function __invoke(Panorama $panorama, Notifications $notifications): void
    {
        $now = CarbonImmutable::now($this->event->timezone);
        $notifiables = $this->recipients();

        try {
            $pending = $panorama->queue($this->report, $this->params instanceof Closure ? ($this->params)($now) : $this->params)
                ->format($this->format)
                ->stamped($now)
                ->store($this->disk, $this->directory)
                ->notify(...$notifiables);

            if ($this->filename !== null) {
                $pending->named($this->filename instanceof Closure ? ($this->filename)($now) : $this->filename);
            }

            if ($this->connection !== null) {
                $pending->onConnection($this->connection);
            }

            if ($this->queue !== null) {
                $pending->onQueue($this->queue);
            }
        } catch (Throwable $failure) {
            if ($notifiables !== []) {
                $notifications->send($notifiables, $this->reports->failed(
                    $this->key,
                    $this->name,
                    $this->format,
                    sprintf('%s: %s', $failure::class, $failure->getMessage()),
                ));
            }

            throw $failure;
        }

        $pending->dispatch();
    }

    /**
     * Pass a frequency or a constraint through to the scheduled event, and keep
     * chaining on the report.
     *
     * @param array<int,mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $result = $this->event->{$method}(...$arguments);

        return $result === $this->event ? $this : $result;
    }

    /**
     * The notifiables, with closures called and lists flattened.
     *
     * @return list<mixed>
     */
    private function recipients(): array
    {
        $recipients = [];

        foreach ($this->notifiables as $notifiable) {
            $resolved = $notifiable instanceof Closure ? $notifiable() : $notifiable;

            if (is_iterable($resolved)) {
                foreach ($resolved as $one) {
                    $recipients[] = $one;
                }
            } elseif ($resolved !== null) {
                $recipients[] = $resolved;
            }
        }

        return $recipients;
    }
}
