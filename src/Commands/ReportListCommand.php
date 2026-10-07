<?php

namespace Aldeebhasan\Panorama\Commands;

use Aldeebhasan\Panorama\Panorama;
use Illuminate\Console\Command;

class ReportListCommand extends Command
{
    protected $signature = 'panorama:list';
    protected $description = 'List all the registered reports';

    public function handle(Panorama $panorama): int
    {
        $reports = $panorama->all();

        if (empty($reports)) {
            $this->components->warn('No reports registered, add them to the [panorama.reports] config.');

            return self::SUCCESS;
        }

        $this->table(
            ['Key', 'Name'],
            collect($reports)->map(fn(string $name, string $key) => [$key, $name])->values()->all()
        );

        return self::SUCCESS;
    }
}
