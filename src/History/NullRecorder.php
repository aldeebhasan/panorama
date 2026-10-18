<?php

namespace Aldeebhasan\Panorama\History;

/**
 * Keeps no history. The recorder in place while history is disabled.
 */
final class NullRecorder implements RecorderContract
{
    public function started(Recording $recording): int|string|null
    {
        return null;
    }

    public function finished(Recording $recording): void
    {
    }
}
