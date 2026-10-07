<?php

namespace Aldeebhasan\Panorama\Schema;

use Aldeebhasan\Panorama\Exceptions\SchemaException;
use Stringable;

/**
 * A declared link from one dataset's field to another dataset's field, used to
 * infer join keys.
 */
final readonly class Relation implements Stringable
{
    public function __construct(
        public string $dataset,
        public string $field,
    ) {
    }

    /**
     * Parse a reference written as `dataset.field`.
     *
     * @throws SchemaException
     */
    public static function parse(string $reference): self
    {
        $parts = explode('.', $reference);

        if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
            throw SchemaException::malformedReference($reference);
        }

        return new self(trim($parts[0]), trim($parts[1]));
    }

    public function __toString(): string
    {
        return $this->dataset . '.' . $this->field;
    }
}
