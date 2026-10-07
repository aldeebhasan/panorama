<?php

namespace Aldeebhasan\Panorama\Schema\Casts;

use Aldeebhasan\Panorama\Exceptions\CastFailed;
use Aldeebhasan\Panorama\Schema\Field;
use BackedEnum;

/**
 * A string restricted to a declared set of values. Backed enum instances are
 * unwrapped to their value first, so a source can hand back either.
 */
final class EnumCast implements Cast
{
    public function cast(mixed $value, Field $field): string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            throw CastFailed::because('is not one of the allowed values');
        }

        $value = trim($value);

        if (!in_array($value, $field->values, true)) {
            throw CastFailed::because(sprintf('is not one of [%s]', implode(', ', $field->values)));
        }

        return $value;
    }
}
