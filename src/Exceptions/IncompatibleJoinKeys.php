<?php

namespace Aldeebhasan\Panorama\Exceptions;

use Aldeebhasan\Panorama\Schema\FieldType;

/**
 * Thrown before a merge runs, when two join keys could never match.
 *
 * This is the failure the design calls out by name: without the check, a
 * string key joined against an int key returns an empty result that looks
 * like "no data" rather than a mistake.
 */
class IncompatibleJoinKeys extends PanoramaException
{
    public static function types(
        string $localDataset,
        string $localField,
        FieldType $localType,
        string $foreignDataset,
        string $foreignField,
        FieldType $foreignType,
    ): self {
        return new self(sprintf(
            'Cannot join [%s.%s] (%s) on [%s.%s] (%s): the declared types are not comparable, '
            . 'so the join would silently return nothing.',
            $localDataset,
            $localField,
            $localType->value,
            $foreignDataset,
            $foreignField,
            $foreignType->value,
        ));
    }

    public static function noRelation(string $localDataset, string $foreignDataset): self
    {
        return new self(sprintf(
            'No relation is declared between [%s] and [%s]. Add references() to one of the schemas, '
            . 'or name the keys on the join.',
            $localDataset,
            $foreignDataset,
        ));
    }

    /**
     * @param list<string> $candidates
     */
    public static function ambiguousRelation(string $localDataset, string $foreignDataset, array $candidates): self
    {
        return new self(sprintf(
            '[%s] and [%s] are related more than once (%s). Name the keys on the join to say which to use.',
            $localDataset,
            $foreignDataset,
            implode(', ', $candidates),
        ));
    }
}
