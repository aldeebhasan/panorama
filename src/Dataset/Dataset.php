<?php

namespace Aldeebhasan\Panorama\Dataset;

use Aldeebhasan\Panorama\Exceptions\PanoramaException;
use Aldeebhasan\Panorama\Exceptions\SourceUnavailable;
use Aldeebhasan\Panorama\Schema\Processor;
use Aldeebhasan\Panorama\Schema\Schema;
use Aldeebhasan\Panorama\Schema\ViolationLog;
use Generator;
use Throwable;

/**
 * Where a report's data comes from, plus the shape it guarantees.
 *
 * A dataset fetches its own rows, with whatever the application already uses
 * to reach the source: a query builder cursor, an HTTP client, an Eloquent
 * model. It is defined once and then used standalone or merged with any other
 * dataset. Consumers never see raw rows, only the normalized ones
 * {@see rows()} yields.
 */
abstract class Dataset
{
    /**
     * The stable identifier reports and queries refer to this dataset by.
     * Defaults to the snake-cased class basename.
     */
    public string $name = '';

    private ?Processor $processor = null;

    /**
     * Pull the raw rows. Push filters down to the source wherever the source
     * can apply them, so a date range narrows the fetch instead of the result.
     *
     * @return iterable<array-key,mixed>
     */
    abstract public function fetch(Params $params): iterable;

    /**
     * The contract every row this dataset yields honours.
     */
    abstract public function schema(): Schema;

    /**
     * The filters this dataset accepts, as validation rules. An empty list
     * means every filter is passed through untouched.
     *
     * @return array<string,mixed>
     */
    public function parameters(): array
    {
        return [];
    }

    public function name(): string
    {
        if ($this->name !== '') {
            return $this->name;
        }

        $basename = basename(str_replace('\\', '/', static::class));

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $basename));
    }

    /**
     * Normalized rows, one at a time.
     *
     * @return Generator<int,array<string,mixed>>
     *
     * @throws PanoramaException
     */
    public function rows(Params $params): Generator
    {
        $processor = $this->processor();

        try {
            yield from $processor->process($this->fetch($this->accepted($params)));
        } catch (PanoramaException $known) {
            // Schema violations already name the dataset and the field.
            throw $known;
        } catch (Throwable $failure) {
            throw SourceUnavailable::fetching($this->name(), $failure);
        }
    }

    /**
     * The processor behind {@see rows()}, built once so its log survives the
     * iteration.
     */
    public function processor(): Processor
    {
        return $this->processor ??= new Processor($this->schema(), $this->name());
    }

    /**
     * What the last pass over this dataset dropped or patched.
     */
    public function log(): ViolationLog
    {
        return $this->processor()->log();
    }

    /**
     * The subset of the run's filters this dataset declared an interest in.
     */
    protected function accepted(Params $params): Params
    {
        $declared = array_keys($this->parameters());

        return $declared === [] ? $params : $params->only($declared);
    }
}
