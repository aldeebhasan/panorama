<?php

namespace Aldeebhasan\Panorama\Schema\Casts;

use Aldeebhasan\Panorama\Exceptions\CastFailed;
use Aldeebhasan\Panorama\Schema\Field;

/**
 * Accepts anything that represents a whole number exactly, including the
 * numeric strings database drivers hand back. Values with a fractional part are
 * rejected rather than truncated, because silently dropping cents is the kind
 * of wrongness this package exists to prevent.
 */
final class IntCast implements Cast
{
    public function cast(mixed $value, Field $field): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '' || !is_numeric($value)) {
                throw CastFailed::because('is not a valid int');
            }

            $value = $value + 0;
        }

        if (!is_int($value) && !is_float($value)) {
            throw CastFailed::because('is not a valid int');
        }

        if (is_int($value)) {
            return $value;
        }

        if (!is_finite($value)) {
            throw CastFailed::because('is not a finite int');
        }

        if ($value !== floor($value)) {
            throw CastFailed::because('is not a whole number');
        }

        if ($value < (float) PHP_INT_MIN || $value > (float) PHP_INT_MAX) {
            throw CastFailed::because('is out of the int range');
        }

        return (int) $value;
    }
}
