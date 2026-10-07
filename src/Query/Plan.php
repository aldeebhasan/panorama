<?php

namespace Aldeebhasan\Panorama\Query;

use Aldeebhasan\Panorama\Dataset\Dataset;

/**
 * A validated query: joins resolved, shapes checked, and the exact set of
 * datasets that has to be loaded for it.
 */
final readonly class Plan
{
    /**
     * @param array<string,Dataset> $datasets keyed by name, in load order
     */
    public function __construct(
        public Query $query,
        public array $datasets,
    ) {
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->datasets);
    }
}
