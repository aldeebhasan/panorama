<?php

namespace Aldeebhasan\Panorama\Workspace;

/**
 * A query turned into SQL, with its values kept separate for binding.
 */
final readonly class Compiled
{
    /**
     * @param list<mixed> $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings = [],
    ) {
    }
}
