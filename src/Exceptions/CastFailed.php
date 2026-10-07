<?php

namespace Aldeebhasan\Panorama\Exceptions;

/**
 * Raised by a cast when a value cannot be converted to the declared type.
 *
 * This is an internal control flow signal: the schema processor catches it and
 * turns it into a violation, which the dataset's policy then decides about.
 *
 * @internal
 */
class CastFailed extends PanoramaException
{
    /**
     * Describe why the value was rejected, e.g. "is not a valid int".
     */
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
