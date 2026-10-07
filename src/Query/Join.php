<?php

namespace Aldeebhasan\Panorama\Query;

/**
 * One dataset brought into a query, and the keys it is matched on.
 *
 * The keys are optional when the join is declared: a join with none is
 * resolved by the planner from the `references()` the two schemas declare.
 */
final readonly class Join
{
    public function __construct(
        public string $dataset,
        public JoinType $type = JoinType::Inner,
        public ?string $localKey = null,
        public ?string $foreignKey = null,
    ) {
    }

    public function isResolved(): bool
    {
        return $this->localKey !== null && $this->foreignKey !== null;
    }

    public function on(string $localKey, string $foreignKey): self
    {
        return new self($this->dataset, $this->type, $localKey, $foreignKey);
    }
}
