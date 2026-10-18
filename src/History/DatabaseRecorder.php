<?php

namespace Aldeebhasan\Panorama\History;

use DateTimeInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Keeps the history in a database table, `report_runs` unless configured.
 *
 * A row is inserted as the run starts, so a run that never finishes stays
 * visible as `running`, and updated once it settles. Read the table back
 * through {@see RecordedRun}.
 */
final class DatabaseRecorder implements RecorderContract
{
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly string $table = 'report_runs',
        private readonly ?string $connection = null,
    ) {
    }

    public function started(Recording $recording): int
    {
        return (int) $this->connections->connection($this->connection)->table($this->table)->insertGetId([
            'report' => $recording->report(),
            'name' => $recording->name(),
            'params' => json_encode($recording->params(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'format' => $recording->format(),
            'filename' => $recording->filename(),
            'destination' => $recording->destination(),
            'user_id' => $recording->user(),
            'status' => $recording->status()?->value,
            'started_at' => self::timestamp($recording->startedAt()),
        ]);
    }

    public function finished(Recording $recording): void
    {
        if ($recording->id() === null) {
            return;
        }

        $this->connections->connection($this->connection)->table($this->table)
            ->where('id', $recording->id())
            ->update([
                'destination' => $recording->destination(),
                'path' => $recording->path(),
                'status' => $recording->status()?->value,
                'rows' => $recording->rows(),
                'bytes' => $recording->bytes(),
                'error' => $recording->error(),
                'finished_at' => self::timestamp($recording->finishedAt()),
                'duration_ms' => $recording->duration(),
            ]);
    }

    private static function timestamp(?DateTimeInterface $at): ?string
    {
        return $at?->format('Y-m-d H:i:s');
    }
}
