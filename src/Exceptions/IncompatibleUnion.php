<?php

namespace Aldeebhasan\Panorama\Exceptions;

/**
 * Thrown before a union runs, when its members are not the same shape.
 */
class IncompatibleUnion extends PanoramaException
{
    /**
     * @param list<string> $missing
     * @param list<string> $extra
     */
    public static function shape(string $first, string $other, array $missing, array $extra): self
    {
        $problems = [];

        if ($missing !== []) {
            $problems[] = sprintf('is missing [%s]', implode(', ', $missing));
        }

        if ($extra !== []) {
            $problems[] = sprintf('adds [%s]', implode(', ', $extra));
        }

        return new self(sprintf(
            'Cannot union [%s] with [%s]: it %s. Map the fields explicitly in the schemas first.',
            $first,
            $other,
            implode(' and ', $problems),
        ));
    }

    public static function fieldType(string $first, string $other, string $field, string $firstType, string $otherType): self
    {
        return new self(sprintf(
            'Cannot union [%s] with [%s]: the field [%s] is %s in one and %s in the other.',
            $first,
            $other,
            $field,
            $firstType,
            $otherType,
        ));
    }

    public static function tooFew(): self
    {
        return new self('A union needs at least two datasets.');
    }
}
