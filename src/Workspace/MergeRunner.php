<?php

namespace Aldeebhasan\Panorama\Workspace;

use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Query\Planner;
use Aldeebhasan\Panorama\Query\Query;
use Aldeebhasan\Panorama\Query\Result;
use Throwable;

/**
 * Plans a query, loads only the datasets it names, and streams the merge back.
 *
 * The result carries the workspace's lifetime with it: the tables are dropped
 * when the rows run out, or when the generator is abandoned, so a caller never
 * has to remember to clean up and the tables never disappear mid-stream.
 */
final class MergeRunner
{
    public function __construct(
        private readonly Planner $planner,
        private readonly WorkspaceFactory $workspaces,
    ) {
    }

    public function run(Query $query, ?Params $params = null, ?string $engine = null): Result
    {
        $params ??= Params::make();
        $plan = $this->planner->plan($query);
        $workspace = $this->workspaces->make($engine);

        try {
            foreach ($plan->datasets as $dataset) {
                // attach() is the engine reading the source itself; load() is
                // the fallback that streams the rows through PHP.
                if (!$workspace->attach($dataset)) {
                    $workspace->load($dataset, $params);
                }
            }

            $result = $workspace->query($plan);
        } catch (Throwable $failure) {
            $workspace->drop();

            throw $failure;
        }

        // The result owns the workspace from here: the tables live exactly as
        // long as the rows are being read.
        return $result->whenDone($workspace->drop(...));
    }

    /**
     * How many rows each dataset contributed, for a run log. Loads everything,
     * so it is for diagnostics rather than for reports.
     *
     * @return array<string,int>
     */
    public function load(Query $query, ?Params $params = null, ?string $engine = null): array
    {
        $params ??= Params::make();
        $plan = $this->planner->plan($query);
        $workspace = $this->workspaces->make($engine);

        try {
            $loaded = [];
            foreach ($plan->datasets as $name => $dataset) {
                $loaded[$name] = $workspace->load($dataset, $params);
            }

            return $loaded;
        } finally {
            $workspace->drop();
        }
    }
}
