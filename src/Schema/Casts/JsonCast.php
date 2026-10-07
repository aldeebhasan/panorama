<?php

namespace Aldeebhasan\Panorama\Schema\Casts;

use Aldeebhasan\Panorama\Exceptions\CastFailed;
use Aldeebhasan\Panorama\Schema\Field;
use JsonException;
use JsonSerializable;
use stdClass;

/**
 * Decodes JSON into PHP arrays. Arrays pass through untouched, strings are
 * decoded, and objects are round tripped so nested structures become arrays
 * too. Workspaces re-encode the value when they store it.
 */
final class JsonCast implements Cast
{
    /**
     * @return array<array-key,mixed>
     */
    public function cast(mixed $value, Field $field): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof JsonSerializable || $value instanceof stdClass) {
            $value = json_encode($value);

            if (!is_string($value)) {
                throw CastFailed::because('is not valid json');
            }
        }

        if (!is_string($value)) {
            throw CastFailed::because('is not valid json');
        }

        try {
            $decoded = json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw CastFailed::because('is not valid json');
        }

        if (!is_array($decoded)) {
            throw CastFailed::because('is not a json object or array');
        }

        return $decoded;
    }
}
