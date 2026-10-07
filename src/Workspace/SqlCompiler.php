<?php

namespace Aldeebhasan\Panorama\Workspace;

use Aldeebhasan\Panorama\Exceptions\QueryException;
use Aldeebhasan\Panorama\Query\Condition;
use Aldeebhasan\Panorama\Query\Plan;
use Aldeebhasan\Panorama\Query\Query;

/**
 * Turns a planned query into SQL for a relational engine.
 *
 * Two choices shape everything here.
 *
 * The workspace tables are **aliased back to their dataset names**, so
 * `sum(orders.amount)` compiles verbatim and no expression a user wrote ever
 * has to be rewritten. Rewriting identifiers inside arbitrary SQL expressions
 * is the kind of thing that works until someone writes a string literal
 * containing a dataset name.
 *
 * Raw SQL cannot be aliased, since the caller owns the `from` clause, so it is
 * **wrapped in CTEs** that name each dataset. That needs an engine with common
 * table expressions: MySQL 8+, PostgreSQL, or SQLite 3.8.3+. The fluent
 * builder has no such requirement.
 *
 * Identifiers are quoted here rather than through the query grammar, because
 * the grammar also applies the connection's table prefix and would prefix our
 * aliases as if they were real tables.
 */
final class SqlCompiler
{
    public function __construct(private readonly string $driver = 'sqlite')
    {
    }

    /**
     * @param array<string,string> $tables dataset name => physical table name
     *
     * @throws QueryException
     */
    public function compile(Plan $plan, array $tables): Compiled
    {
        $query = $plan->query;

        return match (true) {
            $query->isRaw() => $this->compileRaw($query, $tables),
            default => $this->compileSelect($query, $tables),
        };
    }

    /**
     * @param array<string,string> $tables
     *
     * @throws QueryException
     */
    private function compileSelect(Query $query, array $tables): Compiled
    {
        $bindings = [];

        if ($query->isUnion()) {
            $union = $this->compileUnion($query, $tables);
            $from = '(' . $union->sql . ') as ' . $this->quote('unioned');
            $bindings = $union->bindings;
        } else {
            $source = $query->source() ?? throw QueryException::nothingToQuery();
            $joins = $this->compileJoins($query, $tables);

            $from = $this->aliased($tables, $source) . $joins->sql;
            $bindings = $joins->bindings;
        }

        $sql = 'select ' . $this->compileSelects($query) . ' from ' . $from;

        $where = $this->compileConditions($query->wheres());
        if ($where->sql !== '') {
            $sql .= ' where ' . $where->sql;
            $bindings = array_merge($bindings, $where->bindings);
        }

        if ($query->groups() !== []) {
            $sql .= ' group by ' . implode(', ', array_map($this->column(...), $query->groups()));
        }

        $having = $this->compileConditions($query->havings());
        if ($having->sql !== '') {
            $sql .= ' having ' . $having->sql;
            $bindings = array_merge($bindings, $having->bindings);
        }

        if ($query->orders() !== []) {
            $orders = array_map(
                fn(array $order): string => $this->column($order[0]) . ' ' . $order[1],
                $query->orders(),
            );

            $sql .= ' order by ' . implode(', ', $orders);
        }

        // Inlined rather than bound: both are clamped to non-negative ints.
        if ($query->getLimit() !== null) {
            $sql .= ' limit ' . $query->getLimit();
        }

        if ($query->getOffset() !== null) {
            $sql .= ' offset ' . $query->getOffset();
        }

        return new Compiled($sql, $bindings);
    }

    private function compileSelects(Query $query): string
    {
        // Raw by necessity: an aggregate expression is SQL the user wrote.
        return $query->selects() === [] ? '*' : implode(', ', $query->selects());
    }

    /**
     * @param array<string,string> $tables
     *
     * @throws QueryException
     */
    private function compileJoins(Query $query, array $tables): Compiled
    {
        $sql = '';

        foreach ($query->joins() as $join) {
            if (!$join->isResolved()) {
                throw QueryException::halfDeclaredJoin($join->dataset);
            }

            $sql .= sprintf(
                ' %s join %s on %s = %s',
                $join->type->value,
                $this->aliased($tables, $join->dataset),
                $this->column((string) $join->localKey),
                $this->column((string) $join->foreignKey),
            );
        }

        return new Compiled($sql);
    }

    /**
     * @param array<string,string> $tables
     *
     * @throws QueryException
     */
    private function compileUnion(Query $query, array $tables): Compiled
    {
        $parts = [];
        $bindings = [];
        $tag = $query->sourceColumn();

        foreach ($query->unions() as $dataset) {
            $columns = '*';

            if ($tag !== null) {
                $columns = '? as ' . $this->quote($tag) . ', *';
                $bindings[] = $dataset;
            }

            $parts[] = 'select ' . $columns . ' from ' . $this->aliased($tables, $dataset);
        }

        return new Compiled(implode(' union all ', $parts), $bindings);
    }

    /**
     * @param list<Condition> $conditions
     */
    private function compileConditions(array $conditions): Compiled
    {
        $parts = [];
        $bindings = [];

        foreach ($conditions as $condition) {
            $column = $condition->column === '' ? '' : $this->column($condition->column);

            switch ($condition->kind) {
                case 'comparison':
                    $parts[] = $column . ' ' . $condition->operator . ' ?';
                    $bindings[] = $condition->values[0] ?? null;
                    break;

                case 'in':
                case 'notIn':
                    if ($condition->values === []) {
                        // An empty set matches nothing, and its negation everything.
                        $parts[] = $condition->kind === 'in' ? '1 = 0' : '1 = 1';
                        break;
                    }

                    $parts[] = sprintf(
                        '%s %sin (%s)',
                        $column,
                        $condition->kind === 'notIn' ? 'not ' : '',
                        implode(', ', array_fill(0, count($condition->values), '?')),
                    );
                    $bindings = array_merge($bindings, $condition->values);
                    break;

                case 'between':
                    $parts[] = $column . ' between ? and ?';
                    $bindings = array_merge($bindings, $condition->values);
                    break;

                case 'null':
                    $parts[] = $column . ' is null';
                    break;

                case 'notNull':
                    $parts[] = $column . ' is not null';
                    break;

                case 'raw':
                    $parts[] = '(' . $condition->sql . ')';
                    $bindings = array_merge($bindings, $condition->values);
                    break;
            }
        }

        return new Compiled(implode(' and ', $parts), $bindings);
    }

    /**
     * @param array<string,string> $tables
     *
     * @throws QueryException
     */
    private function compileRaw(Query $query, array $tables): Compiled
    {
        $sql = trim((string) $query->sql());

        if (preg_match('/^with\s+recursive\b/i', $sql) === 1) {
            throw QueryException::recursiveRawQuery();
        }

        $ctes = [];
        foreach ($tables as $dataset => $table) {
            $ctes[] = sprintf('%s as (select * from %s)', $this->quote($dataset), $this->quote($table));
        }

        if ($ctes === []) {
            return new Compiled($sql, $query->bindings());
        }

        // A query that already opens with its own CTEs keeps them, ours first.
        $rest = preg_match('/^with\s+/i', $sql) === 1
            ? ', ' . preg_replace('/^with\s+/i', '', $sql)
            : ' ' . $sql;

        return new Compiled('with ' . implode(', ', $ctes) . $rest, $query->bindings());
    }

    /**
     * `"panorama_orders" as "orders"`, so expressions can name the dataset.
     *
     * @param array<string,string> $tables
     *
     * @throws QueryException
     */
    private function aliased(array $tables, string $dataset): string
    {
        $table = $tables[$dataset] ?? throw QueryException::datasetNotLoaded($dataset);

        return $this->quote($table) . ' as ' . $this->quote($dataset);
    }

    /**
     * `orders.amount` to `"orders"."amount"`. The identifier was validated
     * when the query was built, so there is nothing to escape beyond quoting.
     */
    private function column(string $column): string
    {
        return implode('.', array_map($this->quote(...), explode('.', $column)));
    }

    private function quote(string $identifier): string
    {
        return in_array($this->driver, ['mysql', 'mariadb'], true)
            ? '`' . str_replace('`', '``', $identifier) . '`'
            : '"' . str_replace('"', '""', $identifier) . '"';
    }
}
