<?php

namespace Aldeebhasan\Panorama\Contracts;

interface ReportContract
{
    /**
     * The unique key used to resolve the report from the registry.
     */
    public function key(): string;

    /**
     * The human readable name of the report.
     */
    public function name(): string;

    /**
     * Apply the given filters to the report.
     *
     * @param array<string,mixed> $filters
     */
    public function filters(array $filters): static;

    /**
     * Build the report payload.
     *
     * @return array<array-key,mixed>
     */
    public function build(): array;
}
