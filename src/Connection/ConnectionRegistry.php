<?php

namespace Aldeebhasan\Panorama\Connection;

use Aldeebhasan\Panorama\Exceptions\ConnectionException;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\Client\Factory;

/**
 * Builds the connections declared in config, once each, on first use.
 *
 * Instances are memoized because a connection is shared by every dataset that
 * names it, and because registering a fake must replace it everywhere.
 */
final class ConnectionRegistry
{
    /** @var array<string,ConnectionContract> */
    private array $resolved = [];

    /**
     * @param array<array-key,mixed> $config the `connections` section of the config
     */
    public function __construct(
        private readonly array $config,
        private readonly ConnectionResolverInterface $database,
        private readonly Factory $http,
    ) {
    }

    /**
     * Put a ready made connection in place, replacing whatever is configured
     * under the same name. This is how tests swap a real source for fixtures.
     */
    public function register(ConnectionContract $connection): self
    {
        $this->resolved[$connection->name()] = $connection;

        return $this;
    }

    /**
     * @throws ConnectionException
     */
    public function get(string $name): ConnectionContract
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $config = $this->config[$name] ?? null;

        if (!is_array($config)) {
            throw ConnectionException::unknown($name, $this->names());
        }

        return $this->resolved[$name] = $this->build($name, $config);
    }

    public function has(string $name): bool
    {
        return isset($this->resolved[$name]) || is_array($this->config[$name] ?? null);
    }

    /**
     * Every connection name, configured or registered.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_merge(array_keys($this->config), array_keys($this->resolved));

        return array_values(array_unique(array_map(strval(...), $names)));
    }

    /**
     * @param array<array-key,mixed> $config
     *
     * @throws ConnectionException
     */
    private function build(string $name, array $config): ConnectionContract
    {
        $driver = $config['driver'] ?? null;
        $driver = is_string($driver) ? $driver : '';

        return match ($driver) {
            'database' => new DatabaseConnection(
                $name,
                $this->database,
                $this->optionalString($config, 'connection'),
            ),
            'http' => new HttpConnection(
                $name,
                $this->http,
                $this->requiredString($name, $config, 'base_url'),
                $this->stringMap($config, 'headers'),
                $this->map($config, 'auth'),
                $this->int($config, 'timeout', 30),
            ),
            'array' => new ArrayConnection($name, $this->tables($config)),
            default => throw ConnectionException::unknownDriver($name, $driver),
        };
    }

    /**
     * @param array<array-key,mixed> $config
     */
    private function optionalString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<array-key,mixed> $config
     *
     * @throws ConnectionException
     */
    private function requiredString(string $name, array $config, string $key): string
    {
        return $this->optionalString($config, $key) ?? throw ConnectionException::missingOption($name, $key);
    }

    /**
     * @param array<array-key,mixed> $config
     * @return array<string,mixed>
     */
    private function map(array $config, string $key): array
    {
        $value = $config[$key] ?? null;

        if (!is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $option => $setting) {
            $map[(string) $option] = $setting;
        }

        return $map;
    }

    /**
     * @param array<array-key,mixed> $config
     * @return array<string,string>
     */
    private function stringMap(array $config, string $key): array
    {
        $map = [];
        foreach ($this->map($config, $key) as $option => $setting) {
            if (is_string($setting) || is_int($setting) || is_float($setting)) {
                $map[$option] = (string) $setting;
            }
        }

        return $map;
    }

    /**
     * @param array<array-key,mixed> $config
     */
    private function int(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<array-key,mixed> $config
     * @return array<string,iterable<array-key,mixed>>
     */
    private function tables(array $config): array
    {
        $tables = [];
        foreach ($this->map($config, 'tables') as $table => $rows) {
            if (!is_iterable($rows)) {
                continue;
            }

            // Materialized so a generator in config can be read more than once.
            $collected = [];
            foreach ($rows as $row) {
                $collected[] = $row;
            }

            $tables[$table] = $collected;
        }

        return $tables;
    }
}
