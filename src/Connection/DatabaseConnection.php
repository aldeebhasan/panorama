<?php

namespace Aldeebhasan\Panorama\Connection;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;

/**
 * A database the application can already talk to.
 *
 * The connection is resolved lazily through the resolver, so declaring a
 * connection in config never opens a socket until a dataset fetches from it.
 */
final class DatabaseConnection implements ConnectionContract
{
    public function __construct(
        private readonly string $name,
        private readonly ConnectionResolverInterface $resolver,
        private readonly ?string $connection = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function driver(): string
    {
        return 'database';
    }

    /**
     * The name of the underlying application connection, null for the default.
     */
    public function target(): ?string
    {
        return $this->connection;
    }

    public function connection(): ConnectionInterface
    {
        return $this->resolver->connection($this->connection);
    }

    /**
     * Start a query, e.g. `$this->query()->table('orders')->cursor()`.
     */
    public function table(string $table): Builder
    {
        return $this->connection()->table($table);
    }

    /**
     * @param array<array-key,mixed> $bindings
     * @return array<array-key,mixed>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->connection()->select($sql, $bindings);
    }
}
