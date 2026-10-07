<?php

namespace Aldeebhasan\Panorama\Dataset;

use Aldeebhasan\Panorama\Schema\Schema;

/**
 * A dataset backed by fixture rows, standing in for a real source.
 *
 * Rows still go through the schema, so a fake that does not honour the
 * contract fails the same way the real source would. Traversable input is
 * materialized, because a fake is normally iterated more than once.
 */
final class FakeDataset extends Dataset
{
    /** @var array<array-key,mixed> */
    private readonly array $rows;

    /**
     * @param iterable<array-key,mixed> $rows
     */
    public function __construct(
        string $name,
        private readonly Schema $contract,
        iterable $rows = [],
    ) {
        $this->name = $name;
        $this->rows = is_array($rows) ? $rows : iterator_to_array($rows, false);
    }

    public function fetch(Params $params): iterable
    {
        return $this->rows;
    }

    public function schema(): Schema
    {
        return $this->contract;
    }
}
