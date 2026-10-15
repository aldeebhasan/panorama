<?php

namespace Aldeebhasan\Panorama\Query;

use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Exceptions\PanoramaException;
use Aldeebhasan\Panorama\Exceptions\QueryException;
use Closure;

/**
 * A report's merge: which datasets it reads, how they are joined, filtered,
 * grouped and ordered.
 *
 * Built fluently and run in PHP by the {@see Executor}. Nothing is fetched
 * until the rows are asked for, and everything that can be wrong with the
 * query is found before the first dataset is.
 *
 *     $datasets->from('orders')
 *         ->join('crm_customers')
 *         ->where('orders.amount', '>', 5)
 *         ->groupBy('crm_customers.name', ['revenue' => Aggregate::sum('orders.amount')])
 *         ->orderBy('revenue', 'desc');
 *
 * Columns are written `field` or `dataset.field`. An unqualified one must be
 * declared by exactly one dataset in the query.
 */
final class DatasetQuery
{
    private const IDENTIFIER = '[A-Za-z_][A-Za-z0-9_]*';

    /** @var list<Join> */
    private array $joins = [];

    /** @var list<Condition|Closure(array<string,mixed>):bool> */
    private array $wheres = [];

    /** @var list<string> */
    private array $selects = [];

    /** @var list<string>|null null when the query is not grouped */
    private ?array $groups = null;

    /** @var array<string,Aggregate> */
    private array $aggregates = [];

    /** @var list<Condition> */
    private array $havings = [];

    /** @var list<array{string,string}> */
    private array $orders = [];

    private ?int $limit = null;
    private ?int $offset = null;
    private ?string $sourceColumn = null;
    private Params $params;

    /**
     * @param list<string> $unions
     */
    private function __construct(
        private readonly Executor $executor,
        private readonly ?string $source,
        private readonly array $unions = [],
    ) {
        $this->params = Params::make();
    }

    /**
     * Start from one dataset.
     */
    public static function from(Executor $executor, string $dataset): self
    {
        return new self($executor, $dataset);
    }

    /**
     * Append same-shaped datasets to each other.
     */
    public static function union(Executor $executor, string ...$datasets): self
    {
        return new self($executor, null, array_values($datasets));
    }

    /*
    |--------------------------------------------------------------------------
    | Joining
    |--------------------------------------------------------------------------
    */

    /**
     * Bring in the rows of another dataset that match, dropping the rows that
     * have no match. With no keys, they are inferred from the `references()`
     * the two schemas declare.
     *
     * @throws QueryException
     */
    public function join(string $dataset, ?string $localKey = null, ?string $foreignKey = null): self
    {
        return $this->addJoin($dataset, JoinType::Inner, $localKey, $foreignKey);
    }

    /**
     * Like {@see join()}, but rows without a match are kept, with every field
     * of the joined dataset null.
     *
     * @throws QueryException
     */
    public function leftJoin(string $dataset, ?string $localKey = null, ?string $foreignKey = null): self
    {
        return $this->addJoin($dataset, JoinType::Left, $localKey, $foreignKey);
    }

    /*
    |--------------------------------------------------------------------------
    | Filtering, applied to the rows before they are grouped
    |--------------------------------------------------------------------------
    */

    /**
     * @throws QueryException
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        // where('status', 'draft') reads better than forcing the operator.
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = Condition::comparison($this->column($column), $this->operator($operator), $value);

        return $this;
    }

    /**
     * @param list<mixed> $values
     *
     * @throws QueryException
     */
    public function whereIn(string $column, array $values): self
    {
        $this->wheres[] = Condition::in($this->column($column), $values);

        return $this;
    }

    /**
     * @param list<mixed> $values
     *
     * @throws QueryException
     */
    public function whereNotIn(string $column, array $values): self
    {
        $this->wheres[] = Condition::in($this->column($column), $values, not: true);

        return $this;
    }

    /**
     * Inclusive on both ends.
     *
     * @throws QueryException
     */
    public function whereBetween(string $column, mixed $from, mixed $to): self
    {
        $this->wheres[] = Condition::between($this->column($column), $from, $to);

        return $this;
    }

    /**
     * @throws QueryException
     */
    public function whereNull(string $column): self
    {
        $this->wheres[] = Condition::null($this->column($column));

        return $this;
    }

    /**
     * @throws QueryException
     */
    public function whereNotNull(string $column): self
    {
        $this->wheres[] = Condition::null($this->column($column), not: true);

        return $this;
    }

    /**
     * Any test the conditions above cannot express. The callback receives the
     * row keyed by `dataset.field`, or by `field` alone in a union.
     *
     * @param Closure(array<string,mixed>):bool $callback
     */
    public function filter(Closure $callback): self
    {
        $this->wheres[] = $callback;

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Grouping
    |--------------------------------------------------------------------------
    */

    /**
     * Collapse the rows into one per distinct value of the columns, computing
     * the aggregates for each. The output is the group columns, named by their
     * field or their `as` alias, followed by the aggregates under their keys.
     *
     * @param string|list<string> $columns
     * @param array<string,Aggregate> $aggregates
     *
     * @throws QueryException
     */
    public function groupBy(string|array $columns, array $aggregates = []): self
    {
        $this->groups ??= [];

        foreach ((array) $columns as $column) {
            $this->groups[] = $this->aliased($column);
        }

        return $this->aggregate($aggregates);
    }

    /**
     * Aggregates over every row, or over each group once {@see groupBy()} is
     * called. Ungrouped, the result is always exactly one row.
     *
     * @param array<string,Aggregate> $aggregates
     *
     * @throws QueryException
     */
    public function aggregate(array $aggregates): self
    {
        $this->groups ??= [];

        foreach ($aggregates as $alias => $aggregate) {
            if ($aggregate->column !== null) {
                $this->column($aggregate->column);
            }

            $this->aggregates[$this->identifier((string) $alias)] = $aggregate;
        }

        return $this;
    }

    /**
     * Filter the grouped rows, by their output columns.
     *
     * @throws QueryException
     */
    public function having(string $column, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->havings[] = Condition::comparison($this->identifier($column), $this->operator($operator), $value);

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Shaping
    |--------------------------------------------------------------------------
    */

    /**
     * The columns to return, e.g. `orders.id` or `crm_customers.name as customer`.
     * With none, every field of every dataset in the query, in order.
     *
     * @throws QueryException
     */
    public function select(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->selects[] = $this->aliased($column);
        }

        return $this;
    }

    /**
     * Sort by an output column, or by any field of the datasets in the query.
     *
     * @throws QueryException
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtolower(trim($direction));

        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw QueryException::unsupportedDirection($direction);
        }

        $this->orders[] = [$this->column($column), $direction];

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    /**
     * Add a column naming which dataset each row of a union came from.
     *
     * @throws QueryException
     */
    public function tagSource(string $column = 'source'): self
    {
        $this->sourceColumn = $this->identifier($column);

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Running
    |--------------------------------------------------------------------------
    */

    /**
     * The filters handed to every dataset in the query. Each dataset receives
     * only the ones it declares.
     *
     * @param array<string,mixed>|Params $params
     */
    public function params(array|Params $params): self
    {
        $this->params = $params instanceof Params ? $params : Params::make($params);

        return $this;
    }

    /**
     * The rows, streamed. Checked before anything is fetched.
     *
     * @throws PanoramaException
     */
    public function result(): Result
    {
        return $this->executor->run($this);
    }

    /**
     * Every row at once. Only for results small enough to hold.
     *
     * @return list<array<string,mixed>>
     *
     * @throws PanoramaException
     */
    public function get(): array
    {
        return $this->result()->toArray();
    }

    /**
     * @return array<string,mixed>|null
     *
     * @throws PanoramaException
     */
    public function first(): ?array
    {
        return $this->result()->first();
    }

    /**
     * How many rows each dataset in the query contributes, for diagnostics.
     *
     * @return array<string,int>
     *
     * @throws PanoramaException
     */
    public function loaded(): array
    {
        return $this->executor->count($this);
    }

    /*
    |--------------------------------------------------------------------------
    | Reading, for the executor
    |--------------------------------------------------------------------------
    */

    public function source(): ?string
    {
        return $this->source;
    }

    public function isUnion(): bool
    {
        return $this->source === null;
    }

    /** @return list<string> */
    public function unions(): array
    {
        return $this->unions;
    }

    /**
     * Every dataset the query reads, in order.
     *
     * @return list<string>
     */
    public function datasets(): array
    {
        $names = $this->source === null ? $this->unions : [$this->source];

        foreach ($this->joins as $join) {
            $names[] = $join->dataset;
        }

        return array_values(array_unique($names));
    }

    /** @return list<Join> */
    public function joins(): array
    {
        return $this->joins;
    }

    /** @return list<Condition|Closure(array<string,mixed>):bool> */
    public function wheres(): array
    {
        return $this->wheres;
    }

    /** @return list<string> */
    public function selects(): array
    {
        return $this->selects;
    }

    public function isGrouped(): bool
    {
        return $this->groups !== null;
    }

    /** @return list<string> */
    public function groups(): array
    {
        return $this->groups ?? [];
    }

    /** @return array<string,Aggregate> */
    public function aggregates(): array
    {
        return $this->aggregates;
    }

    /** @return list<Condition> */
    public function havings(): array
    {
        return $this->havings;
    }

    /** @return list<array{string,string}> */
    public function orders(): array
    {
        return $this->orders;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getOffset(): ?int
    {
        return $this->offset;
    }

    public function sourceColumn(): ?string
    {
        return $this->sourceColumn;
    }

    public function parameters(): Params
    {
        return $this->params;
    }

    /**
     * @throws QueryException
     */
    private function addJoin(string $dataset, JoinType $type, ?string $localKey, ?string $foreignKey): self
    {
        if (($localKey === null) !== ($foreignKey === null)) {
            throw QueryException::halfDeclaredJoin($dataset);
        }

        $this->joins[] = new Join(
            $this->identifier($dataset),
            $type,
            $localKey === null ? null : $this->column($localKey),
            $foreignKey === null ? null : $this->column($foreignKey),
        );

        return $this;
    }

    /**
     * @throws QueryException
     */
    private function operator(mixed $operator): string
    {
        $operator = is_string($operator) ? strtolower(trim($operator)) : '=';

        if (!in_array($operator, Condition::OPERATORS, true)) {
            throw QueryException::unsupportedOperator($operator);
        }

        return $operator;
    }

    /**
     * `field` or `dataset.field`.
     *
     * @throws QueryException
     */
    private function column(string $column): string
    {
        $column = trim($column);

        if (preg_match('/^' . self::IDENTIFIER . '(\.' . self::IDENTIFIER . ')?$/', $column) !== 1) {
            throw QueryException::malformedColumn($column);
        }

        return $column;
    }

    /**
     * A column, optionally `as` an alias, normalized to `column as alias`.
     *
     * @throws QueryException
     */
    private function aliased(string $expression): string
    {
        $parts = preg_split('/\s+as\s+/i', trim($expression));

        if ($parts === false || count($parts) > 2) {
            throw QueryException::malformedColumn($expression);
        }

        $column = $this->column($parts[0]);

        return isset($parts[1]) ? $column . ' as ' . $this->identifier($parts[1]) : $column;
    }

    /**
     * @throws QueryException
     */
    private function identifier(string $name): string
    {
        $name = trim($name);

        if (preg_match('/^' . self::IDENTIFIER . '$/', $name) !== 1) {
            throw QueryException::malformedColumn($name);
        }

        return $name;
    }
}
