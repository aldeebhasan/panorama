<?php

namespace Aldeebhasan\Panorama\Schema;

/**
 * One declared field of a dataset: its name, its type and the modifiers that
 * drive mapping, casting and normalization.
 *
 * The properties are written only by the {@see Schema} builder; everything
 * downstream (casts, queries, renderers) treats them as read only.
 */
final class Field
{
    /**
     * @param list<string> $values the allowed values of an enum field
     */
    public function __construct(
        public readonly string $name,
        public readonly FieldType $type,
        public string $from,
        public bool $nullable = false,
        public bool $hasDefault = false,
        public mixed $default = null,
        public int $scale = 2,
        public array $values = [],
        public ?string $timezone = null,
        public ?string $sourceTimezone = null,
        public ?string $currency = null,
        public ?Relation $references = null,
    ) {
    }

    /**
     * The timezone raw values are read in when they carry no explicit offset.
     */
    public function readTimezone(): string
    {
        return $this->sourceTimezone ?? 'UTC';
    }

    /**
     * The timezone values are converted to before they are formatted.
     */
    public function writeTimezone(): string
    {
        return $this->timezone ?? 'UTC';
    }
}
