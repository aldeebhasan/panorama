<?php

namespace Aldeebhasan\Panorama\Exceptions;

/**
 * Thrown when a report cannot be rendered or delivered as asked.
 */
class RenderException extends PanoramaException
{
    /**
     * @param list<string> $available
     */
    public static function unknownFormat(string $format, array $available): self
    {
        sort($available);

        return new self(sprintf(
            'No renderer is registered for [%s]. Available formats are [%s].',
            $format,
            implode(', ', $available),
        ));
    }

    public static function couldNotStore(string $disk, string $path): self
    {
        return new self(sprintf('Could not write the report to [%s] on the [%s] disk.', $path, $disk));
    }
}
