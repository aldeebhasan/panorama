<?php

namespace Aldeebhasan\Panorama\Schema\Casts;

use Aldeebhasan\Panorama\Exceptions\CastFailed;
use Aldeebhasan\Panorama\Schema\Field;

/**
 * Accepts the spellings sources actually use: real booleans, the 1/0 that
 * MySQL and SQLite return, and the usual textual pairs. Anything else fails
 * instead of falling back to PHP's truthiness, which would make every
 * non-empty string true.
 */
final class BoolCast implements Cast
{
    private const TRUE = ['1', 'true', 't', 'yes', 'y', 'on'];
    private const FALSE = ['0', 'false', 'f', 'no', 'n', 'off'];

    public function cast(mixed $value, Field $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return match (true) {
                $value == 1 => true,
                $value == 0 => false,
                default => throw CastFailed::because('is not a valid bool'),
            };
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, self::TRUE, true)) {
                return true;
            }

            if (in_array($normalized, self::FALSE, true)) {
                return false;
            }
        }

        throw CastFailed::because('is not a valid bool');
    }
}
