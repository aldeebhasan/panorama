<?php

namespace Aldeebhasan\Panorama\Exceptions;

use Aldeebhasan\Panorama\Schema\FieldType;

/**
 * Thrown when a schema is declared incorrectly. These are developer mistakes,
 * caught while the schema is being built rather than while rows flow.
 */
class SchemaException extends PanoramaException
{
    public static function noCurrentField(string $modifier): self
    {
        return new self(sprintf('[%s()] must be called right after a field is declared.', $modifier));
    }

    public static function duplicateField(string $name): self
    {
        return new self(sprintf('The field [%s] is declared twice on the same schema.', $name));
    }

    public static function unknownField(string $name): self
    {
        return new self(sprintf('The schema does not declare a field named [%s].', $name));
    }

    /**
     * @param list<FieldType> $allowed
     */
    public static function unsupportedModifier(string $modifier, string $field, FieldType $type, array $allowed): self
    {
        return new self(sprintf(
            '[%s()] cannot be used on the %s field [%s], it only applies to %s fields.',
            $modifier,
            $type->value,
            $field,
            implode(' or ', array_map(fn(FieldType $t) => $t->value, $allowed)),
        ));
    }

    public static function malformedReference(string $reference): self
    {
        return new self(sprintf(
            'The reference [%s] must be written as [dataset.field].',
            $reference,
        ));
    }

    public static function unknownTimezone(string $timezone): self
    {
        return new self(sprintf('[%s] is not a known timezone identifier.', $timezone));
    }

    public static function emptyEnum(string $field): self
    {
        return new self(sprintf('The enum field [%s] must declare at least one allowed value.', $field));
    }
}
