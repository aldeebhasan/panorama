<?php

namespace Aldeebhasan\Panorama\Connection;

/**
 * Rows held in memory, keyed by table name.
 *
 * This is the connection the package's own tests fetch from, and the one users
 * point a dataset at when they want it to read fixtures instead of a real
 * system.
 */
final class ArrayConnection implements ConnectionContract
{
    /**
     * @param array<string,iterable<array-key,mixed>> $tables
     */
    public function __construct(
        private readonly string $name,
        private array $tables = [],
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function driver(): string
    {
        return 'array';
    }

    /**
     * @param iterable<array-key,mixed> $rows
     */
    public function put(string $table, iterable $rows): self
    {
        $this->tables[$table] = $rows;

        return $this;
    }

    /**
     * @return iterable<array-key,mixed>
     */
    public function rows(string $table): iterable
    {
        return $this->tables[$table] ?? [];
    }

    public function has(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return array_keys($this->tables);
    }
}
