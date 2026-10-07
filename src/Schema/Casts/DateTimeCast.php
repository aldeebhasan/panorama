<?php

namespace Aldeebhasan\Panorama\Schema\Casts;

/**
 * A point in time, converted to the field's output timezone and formatted as
 * `Y-m-d H:i:s`.
 */
final class DateTimeCast extends TemporalCast
{
    protected function format(): string
    {
        return 'Y-m-d H:i:s';
    }

    protected function convertsTimezone(): bool
    {
        return true;
    }

    protected function label(): string
    {
        return 'datetime';
    }
}
