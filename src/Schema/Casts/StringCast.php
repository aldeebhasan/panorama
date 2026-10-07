<?php

namespace Aldeebhasan\Panorama\Schema\Casts;

use Aldeebhasan\Panorama\Exceptions\CastFailed;
use Aldeebhasan\Panorama\Schema\Field;
use DateTimeInterface;
use Stringable;

/**
 * Strings are trimmed. Booleans are rejected on purpose: a bool landing on a
 * string field is a mapping mistake, and both `"1"` and `""` would be wrong
 * guesses at what was meant.
 */
final class StringCast implements Cast
{
    public function cast(mixed $value, Field $field): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof Stringable) {
            return trim((string) $value);
        }

        throw CastFailed::because('is not a valid string');
    }
}
