<?php

namespace Aldeebhasan\Panorama\History;

/**
 * Where the history of report runs is kept.
 *
 * A recorder is told twice about each run: once when it starts, so a run that
 * never finishes is still visible, and once when it settles.
 */
interface RecorderContract
{
    /**
     * Record a run that has just started.
     *
     * @return int|string|null the id the run was recorded under, handed back to {@see finished()}
     */
    public function started(Recording $recording): int|string|null;

    /**
     * Record how a started run ended. {@see Recording::id()} is what {@see started()} returned.
     */
    public function finished(Recording $recording): void;
}
