<?php

namespace Aldeebhasan\Panorama\Schema\Casts;

use Aldeebhasan\Panorama\Exceptions\CastFailed;
use Aldeebhasan\Panorama\Schema\Field;

/**
 * Rounds to the declared scale and returns a float.
 *
 * Float, not a string or a bcmath value, so a row reads as a number everywhere.
 * Sums stay exact anyway: an aggregate over a decimal field adds the values as
 * integers at the declared scale. Presentation is the renderer's job, through
 * `Column::money()`.
 */
final class DecimalCast implements Cast
{
    public function cast(mixed $value, Field $field): float
    {
        if (is_bool($value)) {
            throw CastFailed::because('is not a valid decimal');
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw CastFailed::because('is not a valid decimal');
        }

        $number = (float) $value;

        if (!is_finite($number)) {
            throw CastFailed::because('is not a finite decimal');
        }

        return round($number, $field->scale);
    }
}
