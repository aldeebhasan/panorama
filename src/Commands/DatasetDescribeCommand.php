<?php

namespace Aldeebhasan\Panorama\Commands;

use Aldeebhasan\Panorama\Dataset\DatasetRegistry;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Exceptions\PanoramaException;
use Aldeebhasan\Panorama\Schema\Field;
use Illuminate\Console\Command;

class DatasetDescribeCommand extends Command
{
    use InteractsWithInput;

    protected $signature = 'datasets:describe
                            {name : The name of the dataset to describe}
                            {--sample=0 : Fetch this many rows and show them}
                            {--filter=* : Filters to apply while sampling, as key=value pairs}';
    protected $description = 'Show a dataset\'s schema, parameters and, optionally, a sample of its rows';

    public function handle(DatasetRegistry $datasets): int
    {
        $name = $this->text('name');

        try {
            $dataset = $datasets->get($name);
        } catch (PanoramaException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $schema = $dataset->schema();

        $this->components->twoColumnDetail('<fg=green;options=bold>Dataset</>', $dataset->name());
        $this->components->twoColumnDetail('Primary key', implode(', ', $schema->keys()) ?: '—');
        $this->components->twoColumnDetail('On violation', $schema->policy()->value);
        $this->newLine();

        $this->table(
            ['Field', 'Type', 'From', 'Null', 'Default', 'Notes'],
            array_map($this->describe(...), $schema->fields()),
        );

        $this->parameters($dataset->parameters());

        $sample = $this->flag('sample');

        return $sample === null || $sample === '0'
            ? self::SUCCESS
            : $this->sample($datasets, $name, max(0, (int) $sample));
    }

    /**
     * @return array<int,string>
     */
    private function describe(Field $field): array
    {
        $notes = [];

        if ($field->type->value === 'decimal') {
            $notes[] = sprintf('scale %d', $field->scale);
        }

        if ($field->values !== []) {
            $notes[] = implode('|', $field->values);
        }

        if ($field->currency !== null) {
            $notes[] = $field->currency;
        }

        if ($field->timezone !== null) {
            $notes[] = sprintf('tz %s', $field->timezone);
        }

        if ($field->references !== null) {
            $notes[] = sprintf('→ %s', $field->references);
        }

        return [
            $field->name,
            $field->type->value,
            $field->from === $field->name ? '—' : $field->from,
            $field->nullable ? 'yes' : 'no',
            $field->hasDefault ? var_export($field->default, true) : '—',
            implode(', ', $notes) ?: '—',
        ];
    }

    /**
     * @param array<string,mixed> $parameters
     */
    private function parameters(array $parameters): void
    {
        if ($parameters === []) {
            return;
        }

        $this->newLine();
        $this->components->info('Parameters');

        foreach ($parameters as $parameter => $rules) {
            $this->components->twoColumnDetail(
                $parameter,
                is_array($rules)
                    ? implode(', ', array_map($this->stringify(...), $rules))
                    : $this->stringify($rules),
            );
        }
    }

    private function sample(DatasetRegistry $datasets, string $name, int $limit): int
    {
        try {
            // A fresh instance, so the sample's violation log is its own.
            $dataset = $datasets->get($name);
            $rows = [];

            foreach ($dataset->rows(Params::make($this->filters())) as $row) {
                $rows[] = $row;

                if (count($rows) >= $limit) {
                    break;
                }
            }
        } catch (PanoramaException $e) {
            $this->newLine();
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info(sprintf('Sample (%d rows)', count($rows)));

        if ($rows !== []) {
            $this->table(
                array_keys($rows[0]),
                array_map(
                    fn(array $row): array => array_map($this->stringify(...), $row),
                    $rows,
                ),
            );
        }

        $log = $dataset->log();
        if (!$log->clean()) {
            $this->components->warn(sprintf(
                '%d rows skipped, %d values nullified.',
                $log->skipped(),
                $log->nullified(),
            ));
        }

        return self::SUCCESS;
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => (string) json_encode($value),
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }

    /**
     * @return array<string,string>
     */
    private function filters(): array
    {
        $filters = [];
        foreach ($this->strings('filter') as $filter) {
            [$name, $value] = array_pad(explode('=', $filter, 2), 2, '');
            $filters[$name] = $value;
        }

        return $filters;
    }
}
