<?php

namespace Aldeebhasan\Panorama\Exceptions;

use Throwable;

/**
 * Thrown when a dataset's source cannot be reached or refuses the request.
 *
 * Reports mark a dataset `optional()` to downgrade this into a warning.
 */
class SourceUnavailable extends PanoramaException
{
    public function __construct(
        string $message,
        public readonly string $dataset,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fetching(string $dataset, Throwable $previous): self
    {
        return new self(
            sprintf('Dataset [%s] could not be fetched: %s', $dataset, $previous->getMessage()),
            $dataset,
            $previous,
        );
    }

    public static function status(string $dataset, string $url, int $status): self
    {
        return new self(
            sprintf('Dataset [%s] got HTTP %d from [%s].', $dataset, $status, $url),
            $dataset,
        );
    }
}
