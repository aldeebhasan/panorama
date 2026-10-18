<?php

namespace Aldeebhasan\Panorama\History;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * The history of one rendered report, from the moment it starts being produced
 * to the moment it is delivered or fails.
 *
 * A recording reaches its recorder at most twice: when it starts and when it
 * settles. It settles once; whatever happens after that is ignored, so a
 * failure reported by both the renderer and the destination is recorded once.
 */
final class Recording
{
    private int|string|null $id = null;
    private ?RunStatus $status = null;
    private ?string $destination = null;
    private ?string $path = null;
    private int $bytes = 0;
    private ?int $rows = null;
    private ?string $error = null;
    private ?DateTimeImmutable $startedAt = null;
    private ?DateTimeImmutable $finishedAt = null;
    private ?int $duration = null;
    private float $clock = 0.0;

    /**
     * @param array<string,mixed> $params
     * @param Closure():int $countRows how many rows the run has produced so far
     */
    public function __construct(
        private readonly RecorderContract $recorder,
        private readonly string $report,
        private readonly string $name,
        private readonly array $params,
        private readonly string $format,
        private readonly string $filename,
        private readonly ?string $user,
        private readonly Closure $countRows,
    ) {
    }

    /**
     * Mark the run as started and tell the recorder. Only the first call counts.
     */
    public function start(): void
    {
        if ($this->status !== null) {
            return;
        }

        $this->status = RunStatus::Running;
        $this->startedAt = new DateTimeImmutable;
        $this->clock = hrtime(true) / 1e6;

        $this->id = $this->recorder->started($this);
    }

    /**
     * Name the destination the report is going to.
     */
    public function via(string $destination): void
    {
        $this->destination ??= $destination;
    }

    public function wrote(int $bytes): void
    {
        $this->bytes += $bytes;
    }

    /**
     * @param string|null $path where the report was written, for the destinations that write one
     */
    public function succeed(?string $path = null): void
    {
        $this->settle(RunStatus::Succeeded, function () use ($path): void {
            $this->path = $path;
        });
    }

    public function fail(Throwable|string $reason): void
    {
        $this->settle(RunStatus::Failed, function () use ($reason): void {
            $this->error = $reason instanceof Throwable
                ? sprintf('%s: %s', $reason::class, $reason->getMessage())
                : $reason;
        });
    }

    public function isSettled(): bool
    {
        return $this->status !== null && $this->status !== RunStatus::Running;
    }

    /*
    |--------------------------------------------------------------------------
    | What a recorder reads
    |--------------------------------------------------------------------------
    */

    public function id(): int|string|null
    {
        return $this->id;
    }

    public function status(): ?RunStatus
    {
        return $this->status;
    }

    public function report(): string
    {
        return $this->report;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The parameters, reduced to what JSON can hold: dates as ISO 8601, enums
     * as their values, anything else that cannot be written as its class name.
     *
     * @return array<string,mixed>
     */
    public function params(): array
    {
        $params = [];
        foreach ($this->params as $key => $value) {
            $params[$key] = self::portable($value);
        }

        return $params;
    }

    public function format(): string
    {
        return $this->format;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function user(): ?string
    {
        return $this->user;
    }

    public function destination(): ?string
    {
        return $this->destination;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function rows(): ?int
    {
        return $this->rows;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function startedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /**
     * How long the run took, in milliseconds.
     */
    public function duration(): ?int
    {
        return $this->duration;
    }

    /**
     * @param Closure():void $outcome
     */
    private function settle(RunStatus $status, Closure $outcome): void
    {
        if ($this->isSettled()) {
            return;
        }

        // A run can fail before anything was produced, a destination refusing
        // it for instance. It is still a run, so it is still recorded.
        $this->start();

        $outcome();

        $this->status = $status;
        $this->rows = ($this->countRows)();
        $this->finishedAt = new DateTimeImmutable;
        $this->duration = (int) round(hrtime(true) / 1e6 - $this->clock);

        $this->recorder->finished($this);
    }

    private static function portable(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            is_array($value) => array_map(self::portable(...), $value),
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof Stringable => (string) $value,
            is_object($value) => $value::class,
            default => get_debug_type($value),
        };
    }
}
