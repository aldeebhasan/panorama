<?php

namespace Aldeebhasan\Panorama\Commands;

use Aldeebhasan\Panorama\Exceptions\ReportingException;
use Aldeebhasan\Panorama\Panorama;
use Illuminate\Console\Command;

class ReportGenerateCommand extends Command
{
    protected $signature = 'panorama:generate
                            {key : The key of the report to generate}
                            {--filter=* : Filters to apply, as key=value pairs}
                            {--fresh : Bypass the cached payload}';
    protected $description = 'Generate a registered report and dump its payload';

    public function handle(Panorama $panorama): int
    {
        $key = (string) $this->argument('key');
        $filters = $this->parseFilters();

        try {
            if ($this->option('fresh')) {
                $panorama->forget($key, $filters);
            }

            $payload = $panorama->generate($key, $filters);
        } catch (ReportingException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @return array<string,string>
     */
    private function parseFilters(): array
    {
        $filters = [];
        foreach ((array) $this->option('filter') as $filter) {
            [$name, $value] = array_pad(explode('=', (string) $filter, 2), 2, '');
            $filters[$name] = $value;
        }

        return $filters;
    }
}
