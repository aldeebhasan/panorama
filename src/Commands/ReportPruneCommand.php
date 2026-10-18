<?php

namespace Aldeebhasan\Panorama\Commands;

use Aldeebhasan\Panorama\History\NullRecorder;
use Aldeebhasan\Panorama\History\RecordedRun;
use Aldeebhasan\Panorama\History\RecorderContract;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'reports:prune')]
class ReportPruneCommand extends Command
{
    use InteractsWithInput;

    protected $signature = 'reports:prune
                            {--days= : Keep the runs started within this many days, [panorama.history.keep_days] by default}';
    protected $description = 'Delete the recorded report runs older than the retention period';

    public function handle(RecorderContract $recorder): int
    {
        if ($recorder instanceof NullRecorder) {
            $this->components->warn('History is disabled. Enable it with [panorama.history.enabled].');

            return self::SUCCESS;
        }

        $days = $this->flag('days');

        if ($days !== null && (filter_var($days, FILTER_VALIDATE_INT) === false || (int) $days < 0)) {
            $this->components->error(sprintf('[--days] must be a whole number of days, [%s] given.', $days));

            return self::FAILURE;
        }

        $days = $days === null ? RecordedRun::retention() : (int) $days;

        $deleted = RecordedRun::query()
            ->where('started_at', '<', CarbonImmutable::now()->subDays($days)->format('Y-m-d H:i:s'))
            ->delete();

        // A query builder's delete() counts the rows; only an onDelete hook could return anything else.
        $deleted = is_int($deleted) ? $deleted : 0;

        $this->components->info(sprintf('Pruned %d %s older than %d days.', $deleted, $deleted === 1 ? 'run' : 'runs', $days));

        return self::SUCCESS;
    }
}
