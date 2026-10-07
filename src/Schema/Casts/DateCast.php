<?php

namespace Aldeebhasan\Panorama\Schema\Casts;

/**
 * A calendar date, formatted as `Y-m-d`.
 *
 * Dates are never moved between timezones: shifting a pure date by an offset
 * changes which day it is, which is almost never what the source meant. That
 * is why `timezone()` is only allowed on datetime fields.
 */
final class DateCast extends TemporalCast
{
    protected function format(): string
    {
        return 'Y-m-d';
    }

    protected function convertsTimezone(): bool
    {
        return false;
    }

    protected function label(): string
    {
        return 'date';
    }
}
