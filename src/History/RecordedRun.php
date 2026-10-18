<?php

namespace Aldeebhasan\Panorama\History;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the run history, for reading it back.
 *
 * The table and the connection come from the `panorama.history` config, set
 * once by the service provider, the same way {@see DatabaseRecorder} gets them.
 *
 * @property int $id
 * @property string $report
 * @property string $name
 * @property array<string,mixed> $params
 * @property string $format
 * @property string $filename
 * @property string|null $destination
 * @property string|null $path
 * @property string|null $user_id
 * @property RunStatus $status
 * @property int|null $rows
 * @property int|null $bytes
 * @property string|null $error
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $duration_ms
 */
class RecordedRun extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    private static string $historyTable = 'report_runs';
    private static ?string $historyConnection = null;
    private static int $keepDays = 90;

    /**
     * Point the model at the configured table and connection, and say how
     * many days of history `reports:prune` keeps.
     */
    public static function configure(string $table, ?string $connection = null, int $keepDays = 90): void
    {
        self::$historyTable = $table;
        self::$historyConnection = $connection;
        self::$keepDays = $keepDays;
    }

    /**
     * How many days of history are kept when pruning without saying.
     */
    public static function retention(): int
    {
        return self::$keepDays;
    }

    public function getTable(): string
    {
        return self::$historyTable;
    }

    public function getConnectionName(): ?string
    {
        return self::$historyConnection;
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeForReport(Builder $query, string $report): Builder
    {
        return $query->where('report', $report);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', RunStatus::Failed->value);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('started_at')->orderByDesc('id');
    }

    /**
     * @return array<string,string>
     */
    protected function casts(): array
    {
        return [
            'params' => 'array',
            'status' => RunStatus::class,
            'rows' => 'integer',
            'bytes' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
