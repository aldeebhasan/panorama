<?php

namespace Aldeebhasan\Panorama\Schema;

/**
 * The declared types a dataset field can have.
 */
enum FieldType: string
{
    case String = 'string';
    case Int = 'int';
    case Decimal = 'decimal';
    case Bool = 'bool';
    case Date = 'date';
    case Datetime = 'datetime';
    case Json = 'json';
    case Enum = 'enum';

    /**
     * Whether a value of this type can be compared with one of another, which
     * is what decides if two fields may be joined on.
     *
     * Deliberately strict: joining a string key against an int key is the
     * mistake that returns an empty result instead of an error, so it is
     * rejected rather than coerced. Numbers of different precision and the
     * string-backed types are the only cross-type pairs allowed.
     */
    public function comparableWith(self $other): bool
    {
        if ($this === $other) {
            return $this !== self::Json;
        }

        return match (true) {
            $this->isNumeric() && $other->isNumeric() => true,
            $this->isTextual() && $other->isTextual() => true,
            default => false,
        };
    }

    public function isNumeric(): bool
    {
        return $this === self::Int || $this === self::Decimal;
    }

    public function isTextual(): bool
    {
        return $this === self::String || $this === self::Enum;
    }
}
