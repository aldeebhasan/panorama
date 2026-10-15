<?php

namespace Aldeebhasan\Panorama\Exceptions;

/**
 * Thrown when a query is described in a way that cannot be run.
 */
class QueryException extends PanoramaException
{
    public static function unsupportedOperator(string $operator): self
    {
        return new self(sprintf('[%s] is not a supported comparison operator.', $operator));
    }

    public static function unsupportedDirection(string $direction): self
    {
        return new self(sprintf('[%s] is not a sort direction, use asc or desc.', $direction));
    }

    public static function malformedColumn(string $column): self
    {
        return new self(sprintf(
            '[%s] is not a valid column. Write [field] or [dataset.field], optionally followed by [as alias].',
            $column,
        ));
    }

    public static function halfDeclaredJoin(string $dataset): self
    {
        return new self(sprintf(
            'The join on [%s] declares one key but not the other. Give both, or neither to infer them.',
            $dataset,
        ));
    }

    public static function datasetJoinedTwice(): self
    {
        return new self('A dataset can appear in a query only once.');
    }

    public static function joinKeysOutsideJoin(string $dataset, string $localKey, string $foreignKey): self
    {
        return new self(sprintf(
            'The join on [%s] is keyed [%s = %s]. One key must name [%s] and the other a dataset already in the query.',
            $dataset,
            $localKey,
            $foreignKey,
            $dataset,
        ));
    }

    public static function unknownField(string $dataset, string $field): self
    {
        return new self(sprintf('The dataset [%s] declares no field named [%s].', $dataset, $field));
    }

    /**
     * @param list<string> $datasets
     */
    public static function notInQuery(string $dataset, array $datasets): self
    {
        return new self(sprintf(
            'The dataset [%s] is not part of this query, which reads [%s]. Join it first.',
            $dataset,
            implode(', ', $datasets),
        ));
    }

    /**
     * @param list<string> $datasets
     */
    public static function ambiguousColumn(string $column, array $datasets): self
    {
        return new self(sprintf(
            'The column [%s] is declared by more than one dataset (%s). Qualify it as [dataset.%s].',
            $column,
            implode(', ', $datasets),
            $column,
        ));
    }

    /**
     * @param list<string> $columns
     */
    public static function unknownOutputColumn(string $column, array $columns): self
    {
        return new self(sprintf(
            'A grouped query outputs only [%s], so it cannot filter or sort by [%s].',
            implode(', ', $columns),
            $column,
        ));
    }

    public static function nonNumericAggregate(string $aggregate, string $type): self
    {
        return new self(sprintf('Cannot compute [%s] over a %s field.', $aggregate, $type));
    }

    public static function unqualifiedJoinKey(string $column): self
    {
        return new self(sprintf(
            'The join key [%s] must name its dataset, as [dataset.field].',
            $column,
        ));
    }
}
