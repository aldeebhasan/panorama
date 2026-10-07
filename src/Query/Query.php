<?php

namespace Aldeebhasan\Panorama\Query;

use Aldeebhasan\Panorama\Exceptions\QueryException;

/**
 * A merge, described without reference to any engine.
 *
 * This is the portable form: the fluent methods below build it, a planner
 * validates it against the datasets' schemas, and the workspace compiles it
 * into whatever its engine speaks. Nothing here knows about SQL, which is what
 * lets a second engine be added without touching a single report.
 *
 * Three shapes are expressible: a `from` with joins, a `union` of
 * same-shaped datasets, and raw SQL for everything the builder does not cover.
 *
 * Column names are validated as identifiers because they are interpolated into
 * the compiled query. Select expressions, `whereRaw` and `sql()` are raw by
 * necessity and carry the same trust as the SQL a developer writes by hand.
 */
final class Query
{
    private ?string $source = null;

    /** @var list<Join> */
    private array $joins = [];

    /** @var list<string> */
    private array $selects = [];

    /** @var list<Condition> */
    private array $wheres = [];

    /** @var list<string> */
    private array $groups = [];

    /** @var list<Condition> */
    private array $havings = [];

    /** @var list<array{string,string}> */
    private array $orders = [];

    private ?int $limit = null;
    private ?int $offset = null;

    /** @var list<string> */
    private array $unions = [];

    private ?string $sourceColumn = null;
    private ?string $sql = null;

    /** @var list<mixed> */
    private array $bindings = [];

    /** @var list<string> */
    private array $using = [];

    /**
     * Start a query from one dataset.
     */
    public static function from(string $dataset): self
    {
        $query = new self;
        $query->source = $dataset;

        return $query;
    }

    /**
     * Append same-shaped datasets to each other.
     */
    public static function union(string ...$datasets): self
    {
        $query = new self;
        $query->unions = array_values($datasets);

        return $query;
    }

    /**
     * Hand written SQL, for the merges the builder does not cover.
     *
     * @param list<mixed> $bindings
     */
    public static function raw(string $sql, array $bindings = []): self
    {
        $query = new self;
        $query->sql = $sql;
        $query->bindings = array_values($bindings);

        return $query;
    }

    /*
    |--------------------------------------------------------------------------
    | Building
    |--------------------------------------------------------------------------
    */

    /**
     * Bring another dataset in. With no keys, the planner infers them from the
     * `references()` the two schemas declare.
     *
     * @throws QueryException
     */
    public function join(string $dataset, ?string $localKey = null, ?string $foreignKey = null): self
    {
        return $this->addJoin($dataset, JoinType::Inner, $localKey, $foreignKey);
    }

    /**
     * @throws QueryException
     */
    public function leftJoin(string $dataset, ?string $localKey = null, ?string $foreignKey = null): self
    {
        return $this->addJoin($dataset, JoinType::Left, $localKey, $foreignKey);
    }

    /**
     * The columns and expressions to return, e.g. `sum(orders.amount) as revenue`.
     */
    public function select(string ...$expressions): self
    {
        foreach ($expressions as $expression) {
            $this->selects[] = $expression;
        }

        return $this;
    }

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

        $operator = is_string($operator) ? strtolower(trim($operator)) : '=';

        if (!in_array($operator, Condition::OPERATORS, true)) {
            throw QueryException::unsupportedOperator($operator);
        }

        $this->wheres[] = Condition::comparison($this->identifier($column), $operator, $value);

        return $this;
    }

    /**
     * @param list<mixed> $values
     *
     * @throws QueryException
     */
    public function whereIn(string $column, array $values): self
    {
        $this->wheres[] = Condition::in($this->identifier($column), $values);

        return $this;
    }

    /**
     * @param list<mixed> $values
     *
     * @throws QueryException
     */
    public function whereNotIn(string $column, array $values): self
    {
        $this->wheres[] = Condition::in($this->identifier($column), $values, not: true);

        return $this;
    }

    /**
     * @throws QueryException
     */
    public function whereBetween(string $column, mixed $from, mixed $to): self
    {
        $this->wheres[] = Condition::between($this->identifier($column), $from, $to);

        return $this;
    }

    /**
     * @throws QueryException
     */
    public function whereNull(string $column): self
    {
        $this->wheres[] = Condition::null($this->identifier($column));

        return $this;
    }

    /**
     * @throws QueryException
     */
    public function whereNotNull(string $column): self
    {
        $this->wheres[] = Condition::null($this->identifier($column), not: true);

        return $this;
    }

    /**
     * @param list<mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = Condition::raw($sql, $bindings);

        return $this;
    }

    /**
     * @throws QueryException
     */
    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->groups[] = $this->identifier($column);
        }

        return $this;
    }

    /**
     * @param list<mixed> $bindings
     */
    public function havingRaw(string $sql, array $bindings = []): self
    {
        $this->havings[] = Condition::raw($sql, $bindings);

        return $this;
    }

    /**
     * @throws QueryException
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtolower(trim($direction));

        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw QueryException::unsupportedDirection($direction);
        }

        $this->orders[] = [$this->identifier($column), $direction];

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

    /**
     * Declare which datasets a raw SQL query reads, when the names in the SQL
     * cannot be trusted to be found by themselves.
     */
    public function using(string ...$datasets): self
    {
        $this->using = array_values($datasets);

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    public function isRaw(): bool
    {
        return $this->sql !== null;
    }

    public function isUnion(): bool
    {
        return $this->unions !== [];
    }

    public function source(): ?string
    {
        return $this->source;
    }

    /** @return list<Join> */
    public function joins(): array
    {
        return $this->joins;
    }

    /** @return list<string> */
    public function selects(): array
    {
        return $this->selects;
    }

    /** @return list<Condition> */
    public function wheres(): array
    {
        return $this->wheres;
    }

    /** @return list<string> */
    public function groups(): array
    {
        return $this->groups;
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

    /** @return list<string> */
    public function unions(): array
    {
        return $this->unions;
    }

    public function sourceColumn(): ?string
    {
        return $this->sourceColumn;
    }

    public function sql(): ?string
    {
        return $this->sql;
    }

    /** @return list<mixed> */
    public function bindings(): array
    {
        return $this->bindings;
    }

    /** @return list<string> */
    public function declaredDatasets(): array
    {
        return $this->using;
    }

    /**
     * Every dataset this query names, so only those are fetched and loaded.
     *
     * A raw query reports what `using()` declared; the planner falls back to
     * looking for known dataset names in the SQL when nothing was declared.
     *
     * @return list<string>
     */
    public function datasets(): array
    {
        if ($this->isRaw()) {
            return $this->using;
        }

        $datasets = $this->isUnion() ? $this->unions : array_filter([$this->source]);

        foreach ($this->joins as $join) {
            $datasets[] = $join->dataset;
        }

        return array_values(array_unique(array_merge($datasets, $this->using)));
    }

    /**
     * Replace the joins with their resolved form. Used by the planner.
     *
     * @param list<Join> $joins
     */
    public function withJoins(array $joins): self
    {
        $clone = clone $this;
        $clone->joins = $joins;

        return $clone;
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
            $dataset,
            $type,
            $localKey === null ? null : $this->identifier($localKey),
            $foreignKey === null ? null : $this->identifier($foreignKey),
        );

        return $this;
    }

    /**
     * Columns are interpolated into the compiled query, so they must look like
     * identifiers: `amount` or `orders.amount`, nothing else.
     *
     * @throws QueryException
     */
    private function identifier(string $column): string
    {
        $column = trim($column);

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column) !== 1) {
            throw QueryException::malformedColumn($column);
        }

        return $column;
    }
}
