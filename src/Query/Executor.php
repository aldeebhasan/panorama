<?php

namespace Aldeebhasan\Panorama\Query;

use Aldeebhasan\Panorama\Dataset\Dataset;
use Aldeebhasan\Panorama\Dataset\DatasetRegistry;
use Aldeebhasan\Panorama\Exceptions\IncompatibleJoinKeys;
use Aldeebhasan\Panorama\Exceptions\IncompatibleUnion;
use Aldeebhasan\Panorama\Exceptions\PanoramaException;
use Aldeebhasan\Panorama\Exceptions\QueryException;
use Aldeebhasan\Panorama\Schema\Field;
use Closure;
use Generator;

/**
 * Runs a {@see DatasetQuery} in plain PHP.
 *
 * Everything that can be wrong with a query — an unknown dataset or field, an
 * ambiguous column, join keys that could never match, union members of
 * different shapes — is found before the first row is fetched. Failing there
 * costs nothing; failing after a million rows have been pulled costs
 * everything.
 *
 * Then the rows flow: the source streams, each joined dataset is held as a
 * hash on its join key, filters drop rows, groups collapse them, and sorting
 * holds them only when there is an order to apply. Without grouping or
 * sorting, a joined dataset is the only thing ever in memory.
 *
 * Internally a row carries one array per dataset, keyed by dataset name, so a
 * field is always read through the dataset that declares it. A union's rows
 * live under the empty name, since its members share one shape.
 *
 * @phpstan-type Row array<string,array<string,mixed>>
 * @phpstan-type Scope array<string,array<string,Field|null>>
 * @phpstan-type Reader Closure(array<array-key,mixed>):mixed
 * @phpstan-type Filter Closure(array<array-key,mixed>):bool
 */
final class Executor
{
    public function __construct(private readonly DatasetRegistry $registry)
    {
    }

    /**
     * @throws PanoramaException
     */
    public function run(DatasetQuery $query): Result
    {
        $datasets = $this->datasets($query);
        $scope = $this->scope($query, $datasets);
        $joins = $this->joins($query, $datasets, $scope);
        $filters = $this->filters($query, $scope);

        if ($query->isGrouped()) {
            return $this->grouped($query, $datasets, $joins, $filters, $scope);
        }

        $projection = $this->projection($query, $scope);
        $orders = $this->sourceOrders($query, $scope, $projection);

        return new Result(
            $this->ungroupedRows($query, $datasets, $joins, $filters, $projection, $orders),
            array_keys($projection),
        );
    }

    /**
     * How many rows each dataset in the query yields.
     *
     * @return array<string,int>
     *
     * @throws PanoramaException
     */
    public function count(DatasetQuery $query): array
    {
        $counts = [];

        foreach ($this->datasets($query) as $name => $dataset) {
            $counts[$name] = iterator_count($dataset->rows($query->parameters()));
        }

        return $counts;
    }

    /*
    |--------------------------------------------------------------------------
    | Checking, before anything is fetched
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string,Dataset>
     *
     * @throws PanoramaException
     */
    private function datasets(DatasetQuery $query): array
    {
        if ($query->isUnion() && count($query->unions()) < 2) {
            throw IncompatibleUnion::tooFew();
        }

        $datasets = [];
        foreach ($query->datasets() as $name) {
            $datasets[$name] = $this->registry->get($name);
        }

        if (count($query->joins()) + 1 !== count($datasets) && !$query->isUnion()) {
            throw QueryException::datasetJoinedTwice();
        }

        return $datasets;
    }

    /**
     * The fields each dataset contributes, keyed by dataset.
     *
     * @param array<string,Dataset> $datasets
     * @return Scope
     *
     * @throws PanoramaException
     */
    private function scope(DatasetQuery $query, array $datasets): array
    {
        if (!$query->isUnion()) {
            return array_map(static fn(Dataset $dataset): array => $dataset->schema()->fields(), $datasets);
        }

        $names = $query->unions();
        $first = $datasets[$names[0]]->schema()->fields();

        foreach (array_slice($names, 1) as $other) {
            $this->assertSameShape($names[0], $first, $other, $datasets[$other]->schema()->fields());
        }

        $tag = $query->sourceColumn();

        // The tag goes first, as it would in `select 'shop_a' as source, *`.
        return ['' => $tag === null ? $first : [$tag => null] + $first];
    }

    /**
     * Every join with both keys known and checked, the foreign key always on
     * the joined dataset.
     *
     * @param array<string,Dataset> $datasets
     * @param Scope $scope
     * @return list<Join>
     *
     * @throws PanoramaException
     */
    private function joins(DatasetQuery $query, array $datasets, array $scope): array
    {
        $joined = array_filter([$query->source()]);
        $resolved = [];

        foreach ($query->joins() as $join) {
            $join = $join->isResolved()
                ? $this->orient($join, $joined)
                : $this->infer($join, $joined, $datasets);

            [$localDataset, $localField] = $this->split((string) $join->localKey);
            [$foreignDataset, $foreignField] = $this->split((string) $join->foreignKey);

            $local = $this->field($scope, $localDataset, $localField);
            $foreign = $this->field($scope, $foreignDataset, $foreignField);

            if (!$local->type->comparableWith($foreign->type)) {
                throw IncompatibleJoinKeys::types(
                    $localDataset,
                    $localField,
                    $local->type,
                    $foreignDataset,
                    $foreignField,
                    $foreign->type,
                );
            }

            $resolved[] = $join;
            $joined[] = $join->dataset;
        }

        return $resolved;
    }

    /**
     * Keys may be written in either order; one has to name the joined dataset
     * and the other one already in the query.
     *
     * @param list<string> $joined
     *
     * @throws QueryException
     */
    private function orient(Join $join, array $joined): Join
    {
        $local = (string) $join->localKey;
        $foreign = (string) $join->foreignKey;

        [$localDataset] = $this->split($local);
        [$foreignDataset] = $this->split($foreign);

        if ($localDataset === $join->dataset) {
            [$local, $foreign, $localDataset, $foreignDataset] = [$foreign, $local, $foreignDataset, $localDataset];
        }

        if ($foreignDataset !== $join->dataset || !in_array($localDataset, $joined, true)) {
            throw QueryException::joinKeysOutsideJoin($join->dataset, (string) $join->localKey, (string) $join->foreignKey);
        }

        return $join->on($local, $foreign);
    }

    /**
     * Find the one declared relation between the joined dataset and any
     * dataset already in the query, in either direction.
     *
     * @param list<string> $joined
     * @param array<string,Dataset> $datasets
     *
     * @throws IncompatibleJoinKeys
     */
    private function infer(Join $join, array $joined, array $datasets): Join
    {
        $candidates = [];

        foreach ($joined as $existing) {
            foreach ($datasets[$existing]->schema()->relations() as $field => $relation) {
                if ($relation->dataset === $join->dataset) {
                    $candidates[] = [$existing . '.' . $field, $join->dataset . '.' . $relation->field];
                }
            }

            foreach ($datasets[$join->dataset]->schema()->relations() as $field => $relation) {
                if ($relation->dataset === $existing) {
                    $candidates[] = [$existing . '.' . $relation->field, $join->dataset . '.' . $field];
                }
            }
        }

        $candidates = array_values(array_unique($candidates, SORT_REGULAR));
        $from = implode(', ', $joined);

        if ($candidates === []) {
            throw IncompatibleJoinKeys::noRelation($from, $join->dataset);
        }

        if (count($candidates) > 1) {
            throw IncompatibleJoinKeys::ambiguousRelation(
                $from,
                $join->dataset,
                array_map(static fn(array $pair): string => $pair[0] . ' = ' . $pair[1], $candidates),
            );
        }

        return $join->on($candidates[0][0], $candidates[0][1]);
    }

    /**
     * @param array<string,Field> $expected
     * @param array<string,Field> $actual
     *
     * @throws IncompatibleUnion
     */
    private function assertSameShape(string $first, array $expected, string $other, array $actual): void
    {
        $missing = array_values(array_diff(array_keys($expected), array_keys($actual)));
        $extra = array_values(array_diff(array_keys($actual), array_keys($expected)));

        if ($missing !== [] || $extra !== []) {
            throw IncompatibleUnion::shape($first, $other, $missing, $extra);
        }

        foreach ($expected as $name => $field) {
            if ($actual[$name]->type !== $field->type) {
                throw IncompatibleUnion::fieldType($first, $other, $name, $field->type->value, $actual[$name]->type->value);
            }
        }
    }

    /**
     * @param Scope $scope
     * @return list<Filter>
     *
     * @throws QueryException
     */
    private function filters(DatasetQuery $query, array $scope): array
    {
        $filters = [];

        foreach ($query->wheres() as $where) {
            if ($where instanceof Condition) {
                $read = $this->reader(...$this->resolve($where->column, $scope));
                $test = Compare::predicate($where);
                $filters[] = static fn(array $row): bool => $test($read($row));

                continue;
            }

            $filters[] = static fn(array $row): bool => $where(self::flatten($row));
        }

        return $filters;
    }

    /**
     * The output columns of an ungrouped query, each with how to read it.
     *
     * With no select, every field of every dataset in order. A field name two
     * datasets share comes out as `dataset.field` for both, rather than one
     * silently overwriting the other.
     *
     * @param Scope $scope
     * @return array<string,Reader>
     *
     * @throws QueryException
     */
    private function projection(DatasetQuery $query, array $scope): array
    {
        $projection = [];

        if ($query->selects() === []) {
            $seen = array_count_values(array_merge(...array_map(array_keys(...), array_values($scope))));

            foreach ($scope as $dataset => $fields) {
                foreach (array_keys($fields) as $field) {
                    $name = $seen[$field] > 1 ? $dataset . '.' . $field : $field;
                    $projection[$name] = $this->reader($dataset, $field);
                }
            }

            return $projection;
        }

        foreach ($query->selects() as $select) {
            [$column, $alias] = $this->alias($select);
            [$dataset, $field] = $this->resolve($column, $scope);

            $projection[$alias ?? $field] = $this->reader($dataset, $field);
        }

        return $projection;
    }

    /**
     * An unqualified order column names an output column first and a field
     * second, as SQL resolves `order by`.
     *
     * @param Scope $scope
     * @param array<string,Reader> $projection
     * @return list<array{Closure(array<array-key,mixed>,array<string,mixed>):mixed,int}>
     *
     * @throws QueryException
     */
    private function sourceOrders(DatasetQuery $query, array $scope, array $projection): array
    {
        $orders = [];

        foreach ($query->orders() as [$column, $direction]) {
            $sign = $direction === 'desc' ? -1 : 1;

            if (array_key_exists($column, $projection)) {
                $orders[] = [static fn(array $row, array $output): mixed => $output[$column] ?? null, $sign];

                continue;
            }

            $read = $this->reader(...$this->resolve($column, $scope));
            $orders[] = [static fn(array $row, array $output): mixed => $read($row), $sign];
        }

        return $orders;
    }

    /*
    |--------------------------------------------------------------------------
    | Running
    |--------------------------------------------------------------------------
    */

    /**
     * @param array<string,Dataset> $datasets
     * @param list<Join> $joins
     * @param list<Filter> $filters
     * @param array<string,Reader> $projection
     * @param list<array{Closure(array<array-key,mixed>,array<string,mixed>):mixed,int}> $orders
     * @return Generator<int,array<string,mixed>>
     */
    private function ungroupedRows(
        DatasetQuery $query,
        array $datasets,
        array $joins,
        array $filters,
        array $projection,
        array $orders,
    ): Generator {
        $rows = $this->filtered($query, $datasets, $joins, $filters);

        if ($orders === []) {
            $outputs = (static function () use ($rows, $projection): Generator {
                foreach ($rows as $row) {
                    yield self::project($row, $projection);
                }
            })();

            yield from self::page($outputs, $query->getOffset() ?? 0, $query->getLimit());

            return;
        }

        // Sorting needs every row at once, as it does in any engine. Each row
        // is projected once, and sorted by its output and its source together.
        $keyed = [];
        foreach ($rows as $row) {
            $output = self::project($row, $projection);
            $keys = [];

            foreach ($orders as [$value]) {
                $keys[] = $value($row, $output);
            }

            $keyed[] = [$output, $keys];
        }

        yield from self::page(self::sort($keyed, array_column($orders, 1)), $query->getOffset() ?? 0, $query->getLimit());
    }

    /**
     * @param array<string,Dataset> $datasets
     * @param list<Join> $joins
     * @param list<Filter> $filters
     * @param Scope $scope
     *
     * @throws QueryException
     */
    private function grouped(DatasetQuery $query, array $datasets, array $joins, array $filters, array $scope): Result
    {
        $groups = [];
        foreach ($query->groups() as $group) {
            [$column, $alias] = $this->alias($group);
            [$dataset, $field] = $this->resolve($column, $scope);

            $groups[$alias ?? $field] = [$column, $this->reader($dataset, $field)];
        }

        $aggregates = [];
        foreach ($query->aggregates() as $alias => $aggregate) {
            $aggregates[$alias] = $this->accumulator($aggregate, $scope);
        }

        $columns = array_merge(array_keys($groups), array_keys($aggregates));

        $havings = [];
        foreach ($query->havings() as $having) {
            if (!in_array($having->column, $columns, true)) {
                throw QueryException::unknownOutputColumn($having->column, $columns);
            }

            $havings[] = [$having->column, Compare::predicate($having)];
        }

        $orders = [];
        foreach ($query->orders() as [$column, $direction]) {
            $orders[] = [$this->outputColumn($column, $groups, $columns), $direction === 'desc' ? -1 : 1];
        }

        $rows = (function () use ($query, $datasets, $joins, $filters, $groups, $aggregates, $havings, $orders): Generator {
            $outputs = $this->collapse($this->filtered($query, $datasets, $joins, $filters), $groups, $aggregates);

            $kept = [];
            foreach ($outputs as $output) {
                foreach ($havings as [$column, $test]) {
                    if (!$test($output[$column] ?? null)) {
                        continue 2;
                    }
                }

                $kept[] = [$output, array_map(static fn(array $order): mixed => $output[$order[0]] ?? null, $orders)];
            }

            yield from self::page(
                $orders === [] ? array_column($kept, 0) : self::sort($kept, array_column($orders, 1)),
                $query->getOffset() ?? 0,
                $query->getLimit(),
            );
        })();

        return new Result($rows, $columns);
    }

    /**
     * Collapse rows into one per group. Ungrouped aggregates always produce
     * exactly one row, even over no rows at all, as SQL does.
     *
     * @param iterable<Row> $rows
     * @param array<string,array{string,Reader}> $groups
     * @param array<string,array{Aggregate,Reader|null,Field|null}> $aggregates
     * @return list<array<string,mixed>>
     */
    private function collapse(iterable $rows, array $groups, array $aggregates): array
    {
        /** @var array<string,array{list<mixed>,array<string,Accumulator>}> $buckets */
        $buckets = [];

        foreach ($rows as $row) {
            $values = [];
            foreach ($groups as [, $read]) {
                $values[] = $read($row);
            }

            $key = serialize($values);

            if (!isset($buckets[$key])) {
                $buckets[$key] = [$values, array_map(static fn(array $spec): Accumulator => new Accumulator(...$spec), $aggregates)];
            }

            foreach ($buckets[$key][1] as $alias => $accumulator) {
                $accumulator->add($row);
            }
        }

        if ($buckets === [] && $groups === []) {
            $buckets[''] = [[], array_map(static fn(array $spec): Accumulator => new Accumulator(...$spec), $aggregates)];
        }

        $outputs = [];
        foreach ($buckets as [$values, $accumulators]) {
            $output = array_combine(array_keys($groups), $values);

            foreach ($accumulators as $alias => $accumulator) {
                $output[$alias] = $accumulator->result();
            }

            $outputs[] = $output;
        }

        return $outputs;
    }

    /**
     * Every row of the source, joined and filtered.
     *
     * @param array<string,Dataset> $datasets
     * @param list<Join> $joins
     * @param list<Filter> $filters
     * @return Generator<int,Row>
     */
    private function filtered(DatasetQuery $query, array $datasets, array $joins, array $filters): Generator
    {
        foreach ($this->joined($query, $datasets, $joins) as $row) {
            foreach ($filters as $filter) {
                if (!$filter($row)) {
                    continue 2;
                }
            }

            yield $row;
        }
    }

    /**
     * @param array<string,Dataset> $datasets
     * @param list<Join> $joins
     * @return Generator<int,Row>
     */
    private function joined(DatasetQuery $query, array $datasets, array $joins): Generator
    {
        $params = $query->parameters();

        if ($query->isUnion()) {
            $tag = $query->sourceColumn();
            $fields = array_keys($datasets[$query->unions()[0]]->schema()->fields());

            foreach ($query->unions() as $name) {
                foreach ($datasets[$name]->rows($params) as $row) {
                    $values = $tag === null ? [] : [$tag => $name];

                    // In the first member's field order, as a union lines up columns.
                    foreach ($fields as $field) {
                        $values[$field] = $row[$field] ?? null;
                    }

                    yield ['' => $values];
                }
            }

            return;
        }

        // Each joined dataset is read once, into a hash on its join key.
        $hashes = [];
        $empties = [];
        foreach ($joins as $index => $join) {
            [, $field] = $this->split((string) $join->foreignKey);
            $hashes[$index] = [];

            foreach ($datasets[$join->dataset]->rows($params) as $row) {
                $key = Compare::key($row[$field] ?? null);

                if ($key !== null) {
                    $hashes[$index][$key][] = $row;
                }
            }

            $empties[$index] = array_fill_keys(array_keys($datasets[$join->dataset]->schema()->fields()), null);
        }

        $source = (string) $query->source();

        foreach ($datasets[$source]->rows($params) as $row) {
            $rows = [[$source => $row]];

            foreach ($joins as $index => $join) {
                [$dataset, $field] = $this->split((string) $join->localKey);
                $next = [];

                foreach ($rows as $partial) {
                    $key = Compare::key($partial[$dataset][$field] ?? null);
                    $matches = $key === null ? [] : $hashes[$index][$key] ?? [];

                    if ($matches === [] && $join->type === JoinType::Left) {
                        $next[] = $partial + [$join->dataset => $empties[$index]];
                    }

                    foreach ($matches as $match) {
                        $next[] = $partial + [$join->dataset => $match];
                    }
                }

                $rows = $next;
            }

            yield from $rows;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param Scope $scope
     * @return array{Aggregate,Reader|null,Field|null}
     *
     * @throws QueryException
     */
    private function accumulator(Aggregate $aggregate, array $scope): array
    {
        if ($aggregate->column === null) {
            return [$aggregate, null, null];
        }

        [$dataset, $name] = $this->resolve($aggregate->column, $scope);
        $field = $scope[$dataset][$name];

        if (in_array($aggregate->function, ['sum', 'avg'], true) && !($field?->type->isNumeric() ?? false)) {
            throw QueryException::nonNumericAggregate((string) $aggregate, $field?->type->value ?? 'string');
        }

        return [$aggregate, $this->reader($dataset, $name), $field];
    }

    /**
     * A grouped query can only be sorted by what it outputs. A group column
     * may be named the way it was grouped, `dataset.field`, too.
     *
     * @param array<string,array{string,Reader}> $groups
     * @param list<string> $columns
     *
     * @throws QueryException
     */
    private function outputColumn(string $column, array $groups, array $columns): string
    {
        if (in_array($column, $columns, true)) {
            return $column;
        }

        foreach ($groups as $output => [$grouped]) {
            if ($grouped === $column) {
                return $output;
            }
        }

        throw QueryException::unknownOutputColumn($column, $columns);
    }

    /**
     * Which dataset in the query declares a column, and its field.
     *
     * @param Scope $scope
     * @return array{string,string}
     *
     * @throws QueryException
     */
    private function resolve(string $column, array $scope): array
    {
        if (str_contains($column, '.')) {
            [$dataset, $field] = $this->split($column);

            if (!isset($scope[$dataset])) {
                throw QueryException::notInQuery($dataset, array_keys(array_filter($scope, static fn(array $f, string $name): bool => $name !== '', ARRAY_FILTER_USE_BOTH)));
            }

            if (!array_key_exists($field, $scope[$dataset])) {
                throw QueryException::unknownField($dataset, $field);
            }

            return [$dataset, $field];
        }

        $owners = array_keys(array_filter($scope, static fn(array $fields): bool => array_key_exists($column, $fields)));

        return match (count($owners)) {
            1 => [$owners[0], $column],
            0 => throw QueryException::unknownField(implode(', ', array_keys($scope)) ?: 'union', $column),
            default => throw QueryException::ambiguousColumn($column, $owners),
        };
    }

    /**
     * @param Scope $scope
     *
     * @throws QueryException
     */
    private function field(array $scope, string $dataset, string $name): Field
    {
        $field = $scope[$dataset][$name] ?? null;

        return $field ?? throw QueryException::unknownField($dataset, $name);
    }

    /**
     * @return array{string,string}
     *
     * @throws QueryException
     */
    private function split(string $column): array
    {
        $parts = explode('.', $column);

        if (count($parts) !== 2) {
            throw QueryException::unqualifiedJoinKey($column);
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * `column as alias` into its two halves.
     *
     * @return array{string,string|null}
     */
    private function alias(string $expression): array
    {
        $parts = explode(' as ', $expression, 2);

        return [$parts[0], $parts[1] ?? null];
    }

    /**
     * @return Reader
     */
    private function reader(string $dataset, string $field): Closure
    {
        return static function (array $row) use ($dataset, $field): mixed {
            $fields = $row[$dataset] ?? null;

            return is_array($fields) ? $fields[$field] ?? null : null;
        };
    }

    /**
     * @param array<array-key,mixed> $row
     * @param array<string,Reader> $projection
     * @return array<string,mixed>
     */
    private static function project(array $row, array $projection): array
    {
        $output = [];
        foreach ($projection as $name => $read) {
            $output[$name] = $read($row);
        }

        return $output;
    }

    /**
     * The row as `dataset.field => value`, or `field => value` in a union.
     *
     * @param array<array-key,mixed> $row
     * @return array<string,mixed>
     */
    private static function flatten(array $row): array
    {
        $flat = [];

        foreach ($row as $dataset => $fields) {
            foreach (is_array($fields) ? $fields : [] as $field => $value) {
                $flat[$dataset === '' ? (string) $field : $dataset . '.' . $field] = $value;
            }
        }

        return $flat;
    }

    /**
     * Stable, so rows the ordering cannot tell apart keep their order.
     *
     * @param list<array{array<string,mixed>,list<mixed>}> $keyed
     * @param list<int> $directions
     * @return list<array<string,mixed>>
     */
    private static function sort(array $keyed, array $directions): array
    {
        usort($keyed, static function (array $a, array $b) use ($directions): int {
            foreach ($directions as $index => $direction) {
                $compared = Compare::order($a[1][$index] ?? null, $b[1][$index] ?? null) * $direction;

                if ($compared !== 0) {
                    return $compared;
                }
            }

            return 0;
        });

        return array_column($keyed, 0);
    }

    /**
     * @param iterable<array<string,mixed>> $rows
     * @return Generator<int,array<string,mixed>>
     */
    private static function page(iterable $rows, int $offset, ?int $limit): Generator
    {
        if ($limit === 0) {
            return;
        }

        $skipped = 0;
        $yielded = 0;

        foreach ($rows as $row) {
            if ($skipped < $offset) {
                $skipped++;

                continue;
            }

            yield $row;

            if ($limit !== null && ++$yielded >= $limit) {
                return;
            }
        }
    }
}
