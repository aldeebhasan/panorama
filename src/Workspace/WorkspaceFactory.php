<?php

namespace Aldeebhasan\Panorama\Workspace;

use Aldeebhasan\Panorama\Exceptions\WorkspaceException;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Builds the configured workspace engine.
 *
 * A fresh workspace per run, never a singleton: it owns the tables of one
 * merge and drops them when that merge is done.
 */
final class WorkspaceFactory
{
    /**
     * @param array<array-key,mixed> $config the `workspaces` section of the config
     */
    public function __construct(
        private readonly array $config,
        private readonly ConnectionResolverInterface $connections,
        private readonly string $default = 'database',
    ) {
    }

    public function default(): string
    {
        return $this->default;
    }

    /**
     * @throws WorkspaceException
     */
    public function make(?string $engine = null): WorkspaceContract
    {
        $engine ??= $this->default;
        $settings = $this->config[$engine] ?? [];
        $settings = is_array($settings) ? $settings : [];

        return match ($engine) {
            'database' => $this->database($settings),
            default => throw WorkspaceException::unknownEngine($engine),
        };
    }

    /**
     * @param array<array-key,mixed> $settings
     *
     * @throws WorkspaceException
     */
    private function database(array $settings): DatabaseWorkspace
    {
        $name = $settings['connection'] ?? null;
        $connection = $this->connections->connection(is_string($name) && $name !== '' ? $name : null);

        if (!$connection instanceof Connection) {
            throw WorkspaceException::unusableConnection(is_string($name) ? $name : 'default');
        }

        return new DatabaseWorkspace(
            $connection,
            new SqlCompiler($connection->getDriverName()),
            $this->string($settings, 'prefix', 'panorama_'),
            $this->int($settings, 'chunk', 500),
            $this->bool($settings, 'temporary', true),
        );
    }

    /**
     * @param array<array-key,mixed> $settings
     */
    private function string(array $settings, string $key, string $default): string
    {
        $value = $settings[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * @param array<array-key,mixed> $settings
     */
    private function int(array $settings, string $key, int $default): int
    {
        $value = $settings[$key] ?? null;

        return is_numeric($value) ? max(1, (int) $value) : $default;
    }

    /**
     * @param array<array-key,mixed> $settings
     */
    private function bool(array $settings, string $key, bool $default): bool
    {
        $value = $settings[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }
}
