<?php

namespace Aldeebhasan\Panorama\Exceptions;

use Aldeebhasan\Panorama\Schema\Violation;

/**
 * Thrown when a row breaks its dataset's schema and the policy is `fail`.
 */
class SchemaViolation extends PanoramaException
{
    public function __construct(
        string $message,
        public readonly string $dataset,
        public readonly Violation $violation,
    ) {
        parent::__construct($message);
    }

    public static function in(string $dataset, Violation $violation): self
    {
        return new self(
            sprintf('Dataset [%s] %s.', $dataset, $violation->describe()),
            $dataset,
            $violation,
        );
    }
}
