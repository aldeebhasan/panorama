<?php

namespace Aldeebhasan\Panorama\Query;

use Aldeebhasan\Panorama\Dataset\Dataset;
use Aldeebhasan\Panorama\Dataset\DatasetRegistry;
use Aldeebhasan\Panorama\Exceptions\IncompatibleJoinKeys;
use Aldeebhasan\Panorama\Exceptions\IncompatibleUnion;
use Aldeebhasan\Panorama\Exceptions\QueryException;
use Aldeebhasan\Panorama\Exceptions\UnknownDataset;
use Aldeebhasan\Panorama\Schema\FieldType;
use Aldeebhasan\Panorama\Schema\Schema;

/**
 * Checks a query against the schemas it touches, before anything is fetched.
 *
 * Every rule the design asks to be enforced before a merge runs lives here:
 * join keys must have comparable declared types, union members must be the
 * same shape, and only the datasets the query names are collected for loading.
 * Failing at this stage costs nothing; failing after a million rows have been
 * pulled costs everything.
 */
final class Planner
{
    public function __construct(private readonly DatasetRegistry $datasets)
    {
    }

    /**
     * @throws QueryException
     * @throws UnknownDataset
     * @throws IncompatibleJoinKeys
     * @throws IncompatibleUnion
     */
    public function plan(Query $query): Plan
    {
        if ($query->isRaw()) {
            return $this->planRaw($query);
        }

        if ($query->isUnion()) {
            return $this->planUnion($query);
        }

        return $this->planJoins($query);
    }

    /**
     * @throws QueryException
     * @throws UnknownDataset
     * @throws IncompatibleJoinKeys
     */
    private function planJoins(Query $query): Plan
    {
        $source = $query->source() ?? throw QueryException::nothingToQuery();

        $datasets = [$source => $this->datasets->get($source)];
        $joins = [];

        foreach ($query->joins() as $join) {
            $datasets[$join->dataset] = $this->datasets->get($join->dataset);

            $joins[] = $join->isResolved()
                ? $this->checkJoin($join, $datasets)
                : $this->inferJoin($join, $source, $datasets);
        }

        return new Plan($query->withJoins($joins), $datasets);
    }

    /**
     * A declared join still has to name real fields of comparable types.
     *
     * @param array<string,Dataset> $datasets
     *
     * @throws QueryException
     * @throws IncompatibleJoinKeys
     */
    private function checkJoin(Join $join, array $datasets): Join
    {
        [$localDataset, $localField] = $this->split($join->localKey ?? '');
        [$foreignDataset, $foreignField] = $this->split($join->foreignKey ?? '');

        $local = $this->field($datasets, $localDataset, $localField);
        $foreign = $this->field($datasets, $foreignDataset, $foreignField);

        if (!$local->comparableWith($foreign)) {
            throw IncompatibleJoinKeys::types(
                $localDataset,
                $localField,
                $local,
                $foreignDataset,
                $foreignField,
                $foreign,
            );
        }

        return $join;
    }

    /**
     * Find the one relation between the two datasets, in either direction.
     *
     * @param array<string,Dataset> $datasets
     *
     * @throws IncompatibleJoinKeys
     * @throws QueryException
     */
    private function inferJoin(Join $join, string $source, array $datasets): Join
    {
        $candidates = [];

        foreach ($this->relationsBetween($datasets, $source, $join->dataset) as $pair) {
            $candidates[] = $pair;
        }

        foreach ($this->relationsBetween($datasets, $join->dataset, $source) as $pair) {
            $candidates[] = [$pair[1], $pair[0]];
        }

        $candidates = array_values(array_unique($candidates, SORT_REGULAR));

        if ($candidates === []) {
            throw IncompatibleJoinKeys::noRelation($source, $join->dataset);
        }

        if (count($candidates) > 1) {
            throw IncompatibleJoinKeys::ambiguousRelation(
                $source,
                $join->dataset,
                array_map(fn(array $pair): string => $pair[0] . ' = ' . $pair[1], $candidates),
            );
        }

        return $this->checkJoin($join->on($candidates[0][0], $candidates[0][1]), $datasets);
    }

    /**
     * Relations declared on `$from` that point at `$to`, as qualified pairs.
     *
     * @param array<string,Dataset> $datasets
     * @return list<array{string,string}>
     */
    private function relationsBetween(array $datasets, string $from, string $to): array
    {
        $pairs = [];

        foreach ($this->schema($datasets, $from)->relations() as $field => $relation) {
            if ($relation->dataset === $to) {
                $pairs[] = [$from . '.' . $field, $to . '.' . $relation->field];
            }
        }

        return $pairs;
    }

    /**
     * @throws IncompatibleUnion
     * @throws UnknownDataset
     */
    private function planUnion(Query $query): Plan
    {
        $names = $query->unions();

        if (count($names) < 2) {
            throw IncompatibleUnion::tooFew();
        }

        $datasets = [];
        foreach ($names as $name) {
            $datasets[$name] = $this->datasets->get($name);
        }

        $first = $names[0];
        $shape = $this->shape($datasets[$first]->schema());

        foreach (array_slice($names, 1) as $other) {
            $this->assertSameShape($first, $shape, $other, $this->shape($datasets[$other]->schema()));
        }

        return new Plan($query, $datasets);
    }

    /**
     * @param array<string,string> $expected
     * @param array<string,string> $actual
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

        foreach ($expected as $field => $type) {
            if ($actual[$field] !== $type) {
                throw IncompatibleUnion::fieldType($first, $other, $field, $type, $actual[$field]);
            }
        }
    }

    /**
     * @return array<string,string>
     */
    private function shape(Schema $schema): array
    {
        $shape = [];
        foreach ($schema->fields() as $name => $field) {
            $shape[$name] = $field->type->value;
        }

        return $shape;
    }

    /**
     * Raw SQL names its tables itself, so the datasets to load are whatever
     * `using()` declared, or the registered names the SQL mentions.
     *
     * @throws QueryException
     * @throws UnknownDataset
     */
    private function planRaw(Query $query): Plan
    {
        $names = $query->declaredDatasets();

        if ($names === []) {
            $names = $this->mentioned((string) $query->sql());
        }

        if ($names === []) {
            throw QueryException::rawQueryWithoutDatasets();
        }

        $datasets = [];
        foreach ($names as $name) {
            $datasets[$name] = $this->datasets->get($name);
        }

        return new Plan($query, $datasets);
    }

    /**
     * Registered dataset names appearing as whole words in the SQL.
     *
     * @return list<string>
     */
    private function mentioned(string $sql): array
    {
        $found = [];

        foreach ($this->datasets->names() as $name) {
            if (preg_match('/\b' . preg_quote($name, '/') . '\b/i', $sql) === 1) {
                $found[] = $name;
            }
        }

        return $found;
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
     * @param array<string,Dataset> $datasets
     *
     * @throws QueryException
     */
    private function schema(array $datasets, string $name): Schema
    {
        return isset($datasets[$name])
            ? $datasets[$name]->schema()
            : throw QueryException::unknownField($name, '*');
    }

    /**
     * @param array<string,Dataset> $datasets
     *
     * @throws QueryException
     */
    private function field(array $datasets, string $dataset, string $field): FieldType
    {
        $schema = $this->schema($datasets, $dataset);

        if (!$schema->hasField($field)) {
            throw QueryException::unknownField($dataset, $field);
        }

        return $schema->field($field)->type;
    }
}
