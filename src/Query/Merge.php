<?php

namespace Aldeebhasan\Panorama\Query;

use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Workspace\MergeRunner;

/**
 * A merge being described, and the thing that runs it.
 *
 * This is what `Datasets::from()`, `Datasets::union()` and `Datasets::sql()`
 * hand back: the fluent surface of {@see Query} plus the parameters and the
 * engine the run will use. Every builder method is written out rather than
 * forwarded through `__call`, so an IDE and PHPStan both still know what a
 * report is doing.
 */
final class Merge
{
    private Params $params;
    private ?string $engine = null;

    public function __construct(
        private readonly Query $query,
        private readonly MergeRunner $runner,
        ?Params $params = null,
    ) {
        $this->params = $params ?? Params::make();
    }

    /*
    |--------------------------------------------------------------------------
    | Describing the merge
    |--------------------------------------------------------------------------
    */

    public function join(string $dataset, ?string $localKey = null, ?string $foreignKey = null): self
    {
        $this->query->join($dataset, $localKey, $foreignKey);

        return $this;
    }

    public function leftJoin(string $dataset, ?string $localKey = null, ?string $foreignKey = null): self
    {
        $this->query->leftJoin($dataset, $localKey, $foreignKey);

        return $this;
    }

    public function select(string ...$expressions): self
    {
        $this->query->select(...$expressions);

        return $this;
    }

    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        func_num_args() === 2
            ? $this->query->where($column, $operator)
            : $this->query->where($column, $operator, $value);

        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values): self
    {
        $this->query->whereIn($column, $values);

        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    public function whereNotIn(string $column, array $values): self
    {
        $this->query->whereNotIn($column, $values);

        return $this;
    }

    public function whereBetween(string $column, mixed $from, mixed $to): self
    {
        $this->query->whereBetween($column, $from, $to);

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->query->whereNull($column);

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->query->whereNotNull($column);

        return $this;
    }

    /**
     * @param list<mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->query->whereRaw($sql, $bindings);

        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        $this->query->groupBy(...$columns);

        return $this;
    }

    /**
     * @param list<mixed> $bindings
     */
    public function havingRaw(string $sql, array $bindings = []): self
    {
        $this->query->havingRaw($sql, $bindings);

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->query->orderBy($column, $direction);

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->query->limit($limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->query->offset($offset);

        return $this;
    }

    public function tagSource(string $column = 'source'): self
    {
        $this->query->tagSource($column);

        return $this;
    }

    public function using(string ...$datasets): self
    {
        $this->query->using(...$datasets);

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Running it
    |--------------------------------------------------------------------------
    */

    /**
     * The filters passed down to every dataset that declares them.
     *
     * @param array<string,mixed>|Params $params
     */
    public function params(array|Params $params): self
    {
        $this->params = $params instanceof Params ? $params : Params::make($params);

        return $this;
    }

    /**
     * Run this merge on a named engine instead of the configured default.
     */
    public function on(string $engine): self
    {
        $this->engine = $engine;

        return $this;
    }

    /**
     * Stream the merged rows.
     */
    public function result(): Result
    {
        return $this->runner->run($this->query, $this->params, $this->engine);
    }

    /**
     * Materialize the merged rows. For results small enough to hold at once.
     *
     * @return list<array<string,mixed>>
     */
    public function get(): array
    {
        return $this->result()->toArray();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function first(): ?array
    {
        return $this->result()->first();
    }

    /**
     * How many rows each dataset contributed. For diagnostics.
     *
     * @return array<string,int>
     */
    public function loaded(): array
    {
        return $this->runner->load($this->query, $this->params, $this->engine);
    }

    /**
     * The query being described, for reports that want to hand it on.
     */
    public function query(): Query
    {
        return $this->query;
    }

    public function parameters(): Params
    {
        return $this->params;
    }

    public function engine(): ?string
    {
        return $this->engine;
    }
}
