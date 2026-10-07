<?php

namespace Aldeebhasan\Panorama\Schema\Casts;

use Aldeebhasan\Panorama\Exceptions\CastFailed;
use Aldeebhasan\Panorama\Schema\Field;

/**
 * Converts one raw value into the field's declared type, and normalizes it.
 *
 * A cast never receives null: the processor resolves defaults and nullability
 * before it is called.
 */
interface Cast
{
    /**
     * @throws CastFailed when the value cannot be represented as this type
     */
    public function cast(mixed $value, Field $field): mixed;
}
