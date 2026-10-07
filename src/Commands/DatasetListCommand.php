<?php

namespace Aldeebhasan\Panorama\Commands;

use Aldeebhasan\Panorama\Dataset\DatasetRegistry;
use Aldeebhasan\Panorama\Exceptions\PanoramaException;
use Illuminate\Console\Command;

class DatasetListCommand extends Command
{
    protected $signature = 'datasets:list';
    protected $description = 'List the registered datasets, their connections and their relations';

    public function handle(DatasetRegistry $datasets): int
    {
        if ($datasets->names() === []) {
            $this->components->warn('No datasets registered, add them to the [panorama.datasets] config.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($datasets->names() as $name) {
            try {
                $dataset = $datasets->get($name);
                $schema = $dataset->schema();

                $relations = [];
                foreach ($schema->relations() as $field => $relation) {
                    $relations[] = sprintf('%s → %s', $field, $relation);
                }

                $rows[] = [
                    $name,
                    $dataset->connectionName() ?: '—',
                    count($schema->fields()),
                    implode(', ', $schema->keys()) ?: '—',
                    $relations === [] ? '—' : implode(', ', $relations),
                ];
            } catch (PanoramaException $e) {
                $rows[] = [$name, '—', '—', '—', '<error>' . $e->getMessage() . '</error>'];
            }
        }

        $this->table(['Dataset', 'Connection', 'Fields', 'Key', 'Relations'], $rows);

        return self::SUCCESS;
    }
}
