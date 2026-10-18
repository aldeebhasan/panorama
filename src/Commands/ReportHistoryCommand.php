<?php

namespace Aldeebhasan\Panorama\Commands;

use Aldeebhasan\Panorama\History\NullRecorder;
use Aldeebhasan\Panorama\History\RecordedRun;
use Aldeebhasan\Panorama\History\RecorderContract;
use Aldeebhasan\Panorama\History\RunStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'reports:history')]
class ReportHistoryCommand extends Command
{
    use InteractsWithInput;

    protected $signature = 'reports:history
                            {report? : Only the runs of this report}
                            {--failed : Only the runs that failed}
                            {--limit=20 : How many runs to show, newest first}';
    protected $description = 'Show the most recent report runs';

    public function handle(RecorderContract $recorder): int
    {
        if ($recorder instanceof NullRecorder) {
            $this->components->warn('History is disabled. Enable it with [panorama.history.enabled].');

            return self::SUCCESS;
        }

        $query = RecordedRun::query()->latestFirst()->limit(max(1, (int) ($this->flag('limit') ?? 20)));

        if (($report = $this->text('report')) !== '') {
            $query->forReport($report);
        }

        if ($this->option('failed')) {
            $query->failed();
        }

        $runs = $query->get();

        if ($runs->isEmpty()) {
            $this->components->info('No runs recorded yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Report', 'Status', 'Format', 'Destination', 'Rows', 'Duration', 'Started', 'Error'],
            $runs->map(fn(RecordedRun $run): array => [
                $run->id,
                $run->report,
                $this->status($run->status),
                $run->format,
                $run->destination ?? '—',
                $run->rows ?? '—',
                $run->duration_ms === null ? '—' : $run->duration_ms . ' ms',
                $run->started_at->format('Y-m-d H:i:s'),
                $run->error === null ? '' : Str::limit($run->error, 60),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function status(RunStatus $status): string
    {
        return match ($status) {
            RunStatus::Succeeded => '<info>succeeded</info>',
            RunStatus::Failed => '<error>failed</error>',
            RunStatus::Running => '<comment>running</comment>',
        };
    }
}
