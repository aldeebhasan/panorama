<?php

namespace Aldeebhasan\Panorama\Exceptions;

/**
 * Thrown when a query is described in a way that cannot be compiled.
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
            '[%s] is not a valid column. Write [field] or [dataset.field]; for anything else use a raw expression.',
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

    public static function nothingToQuery(): self
    {
        return new self('The query names no dataset to read from.');
    }

    public static function rawQueryWithoutDatasets(): self
    {
        return new self(
            'This raw query mentions no registered dataset. Declare them with using(), so only those are loaded.',
        );
    }

    public static function unknownField(string $dataset, string $field): self
    {
        return new self(sprintf('The dataset [%s] declares no field named [%s].', $dataset, $field));
    }

    public static function recursiveRawQuery(): self
    {
        return new self(
            'A raw query cannot open with [with recursive]: the datasets are themselves injected as '
            . 'common table expressions. Move the recursion into a subquery.',
        );
    }

    public static function datasetNotLoaded(string $dataset): self
    {
        return new self(sprintf(
            'The dataset [%s] was never loaded into the workspace, so the query cannot reference it.',
            $dataset,
        ));
    }

    public static function unqualifiedJoinKey(string $column): self
    {
        return new self(sprintf(
            'The join key [%s] must name its dataset, as [dataset.field].',
            $column,
        ));
    }
}
