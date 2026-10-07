<?php

namespace Aldeebhasan\Panorama\Exceptions;

/**
 * Thrown when the workspace engine cannot be built or used.
 */
class WorkspaceException extends PanoramaException
{
    public static function unknownEngine(string $engine): self
    {
        return new self(sprintf(
            'No workspace engine named [%s]. The MVP ships [database]; set panorama.workspace accordingly.',
            $engine,
        ));
    }

    public static function unusableConnection(string $connection): self
    {
        return new self(sprintf(
            'The workspace connection [%s] is not a database connection that can create tables.',
            $connection,
        ));
    }
}
