<?php

namespace Aldeebhasan\Panorama\Commands;

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Exceptions\PanoramaException;
use Aldeebhasan\Panorama\Panorama;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'reports:list')]
class ReportListCommand extends Command
{
    protected $signature = 'reports:list';
    protected $description = 'List the registered reports, the parameters they accept and the datasets they read';

    public function handle(Panorama $panorama, Datasets $datasets): int
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
                    // Building the query fetches nothing, so it is safe to ask.
                    implode(', ', $report->query($datasets)->datasets()),
                ];
            } catch (PanoramaException $e) {
                $rows[] = [$key, '<error>' . $e->getMessage() . '</error>', '—', '—'];
            }
        }

        $this->table(['Key', 'Name', 'Parameters', 'Datasets'], $rows);

        return self::SUCCESS;
    }
}
