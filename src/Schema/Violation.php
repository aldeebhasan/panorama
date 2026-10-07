<?php

namespace Aldeebhasan\Panorama\Schema;

/**
 * A single thing that went wrong while normalizing one field of one row.
 */
final readonly class Violation
{
    /**
     * @param int $row the one based position of the row in the raw source, so
     *                 the first row of a file is row 1
     */
    public function __construct(
        public string $field,
        public int $row,
        public string $reason,
        public mixed $value = null,
    ) {
    }

    /**
     * A one line description, without the dataset name.
     */
    public function describe(): string
    {
        return sprintf('field [%s] on row %d: %s, got %s', $this->field, $this->row, $this->reason, $this->inspect());
    }

    /**
     * A short, safe rendering of the offending value for error messages.
     */
    public function inspect(): string
    {
        return match (true) {
            is_string($this->value) => sprintf('"%s"', mb_strimwidth($this->value, 0, 50, '...')),
            is_bool($this->value) => $this->value ? 'true' : 'false',
            is_int($this->value), is_float($this->value) => (string) $this->value,
            is_array($this->value) => sprintf('array(%d)', count($this->value)),
            default => get_debug_type($this->value),
        };
    }
}
