<?php

namespace Aldeebhasan\Panorama\Workspace;

use Aldeebhasan\Panorama\Dataset\Dataset;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Query\Plan;
use Aldeebhasan\Panorama\Query\Result;

/**
 * The engine where datasets become tables and queries run.
 *
 * This is the only stage that knows how data is stored and joined, which is
 * why swapping engines never touches a dataset or a report.
 */
interface WorkspaceContract
{
    /**
     * The name this engine is configured under.
     */
    public function name(): string;

    /**
     * Create a table from the dataset's declared schema and stream its rows
     * into it in batches. Returns how many rows were loaded.
     */
    public function load(Dataset $dataset, Params $params): int;

    /**
     * Optional fast path: let the engine read the source itself, skipping PHP
     * entirely. Returns false when this engine cannot, and the caller must
     * {@see load()} instead.
     */
    public function attach(Dataset $dataset): bool;

    /**
     * Run a planned merge and stream the rows back.
     */
    public function query(Plan $plan): Result;

    /**
     * Clean up everything the run created. Safe to call twice.
     */
    public function drop(): void;
}
