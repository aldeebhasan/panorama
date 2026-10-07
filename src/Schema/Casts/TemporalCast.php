<?php

namespace Aldeebhasan\Panorama\Schema\Casts;

use Aldeebhasan\Panorama\Exceptions\CastFailed;
use Aldeebhasan\Panorama\Schema\Field;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;

/**
 * Shared parsing for the date and datetime types.
 *
 * Accepts `DateTimeInterface` instances, unix timestamps as ints, and strings
 * in any format PHP understands. Strings that carry their own offset keep it;
 * the rest are read in the field's source timezone, UTC unless declared.
 *
 * Known limitation: PHP also parses relative expressions, so a source sending
 * the literal `"tomorrow"` is accepted rather than rejected.
 */
abstract class TemporalCast implements Cast
{
    public function cast(mixed $value, Field $field): string
    {
        $instant = $this->parse($value, $field);

        if ($this->convertsTimezone()) {
            $instant = $instant->setTimezone(new DateTimeZone($field->writeTimezone()));
        }

        return $instant->format($this->format());
    }

    /**
     * The output format of this type.
     */
    abstract protected function format(): string;

    /**
     * Whether the parsed instant is moved into the field's output timezone.
     */
    abstract protected function convertsTimezone(): bool;

    /**
     * The type name used in failure messages.
     */
    abstract protected function label(): string;

    /**
     * @throws CastFailed
     */
    private function parse(mixed $value, Field $field): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_int($value)) {
            return new DateTimeImmutable('@' . $value);
        }

        if (!is_string($value) || trim($value) === '') {
            throw CastFailed::because(sprintf('is not a valid %s', $this->label()));
        }

        try {
            return new DateTimeImmutable(trim($value), new DateTimeZone($field->readTimezone()));
        } catch (Exception) {
            throw CastFailed::because(sprintf('is not a valid %s', $this->label()));
        }
    }
}
