<?php

namespace Aldeebhasan\Panorama\Commands;

use Aldeebhasan\Panorama\Exceptions\PanoramaException;
use Aldeebhasan\Panorama\Panorama;
use Aldeebhasan\Panorama\Report\Report;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'reports:list')]
class ReportListCommand extends Command
{
    protected $signature = 'reports:list';
    protected $description = 'List the registered reports and the parameters they accept';

    public function handle(Panorama $panorama): int
    {
        if ($panorama->keys() === []) {
            $this->components->warn('No reports registered, add them to the [panorama.reports] config.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($panorama->keys() as $key) {
            try {
                $report = $panorama->report($key);

                $rows[] = [
                    $key,
                    $report->name(),
                    implode(', ', array_keys($report->parameters())) ?: '—',
                    $report instanceof Report ? ($report->engine() ?? 'default') : 'default',
                ];
            } catch (PanoramaException $e) {
                $rows[] = [$key, '<error>' . $e->getMessage() . '</error>', '—', '—'];
            }
        }

        $this->table(['Key', 'Name', 'Parameters', 'Engine'], $rows);

        return self::SUCCESS;
    }
}
