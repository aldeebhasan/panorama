<?php

namespace Aldeebhasan\Panorama\Dataset;

use Aldeebhasan\Panorama\Connection\ConnectionRegistry;
use Aldeebhasan\Panorama\Exceptions\ConnectionException;
use Aldeebhasan\Panorama\Exceptions\UnknownDataset;
use Aldeebhasan\Panorama\Schema\Schema;
use Psr\Container\ContainerInterface;

/**
 * Where datasets are looked up by name, so reports never depend on class names.
 *
 * Registering a class name resolves it once to learn its name, then builds a
 * fresh instance per lookup: a dataset carries the violation log of its own run
 * and must not be shared between two of them.
 */
final class DatasetRegistry
{
    /** @var array<string,string|Dataset> */
    private array $datasets = [];

    public function __construct(
        private readonly ConnectionRegistry $connections,
        private readonly ContainerInterface $container,
    ) {
    }

    /**
     * Class names are validated when they are resolved, not when they are
     * given, so a name read out of config can be passed straight in.
     *
     * @param class-string<Dataset>|string|Dataset $dataset
     *
     * @throws UnknownDataset
     */
    public function register(string|Dataset $dataset): self
    {
        $instance = is_string($dataset) ? $this->resolve($dataset) : $dataset;

        $this->datasets[$instance->name()] = $dataset;

        return $this;
    }

    /**
     * Register every dataset class found in a directory.
     *
     * @throws UnknownDataset
     */
    public function discover(string $path, string $namespace): self
    {
        if (!is_dir($path)) {
            return $this;
        }

        $files = glob(rtrim($path, '/') . '/*.php');

        foreach ($files === false ? [] : $files as $file) {
            $class = rtrim($namespace, '\\') . '\\' . basename($file, '.php');

            if (class_exists($class) && is_subclass_of($class, Dataset::class)) {
                $this->register($class);
            }
        }

        return $this;
    }

    /**
     * Replace a dataset with fixture rows, keeping the schema it declares.
     *
     * @param iterable<array-key,mixed> $rows
     *
     * @throws UnknownDataset
     */
    public function fake(string $name, iterable $rows, ?Schema $schema = null): self
    {
        $schema ??= $this->get($name)->schema();

        $this->datasets[$name] = new FakeDataset($name, $schema, $rows);

        return $this;
    }

    /**
     * A fresh instance of the named dataset, with its connection attached.
     *
     * @throws UnknownDataset
     * @throws ConnectionException
     */
    public function get(string $name): Dataset
    {
        $dataset = $this->datasets[$name] ?? throw UnknownDataset::named($name, $this->names());

        $instance = is_string($dataset) ? $this->resolve($dataset) : clone $dataset;

        return $instance->connectionName() === ''
            ? $instance
            : $instance->withConnection($this->connections->get($instance->connectionName()));
    }

    public function has(string $name): bool
    {
        return isset($this->datasets[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->datasets);
    }

    /**
     * Every registered dataset, keyed by name. Used by the console commands to
     * describe what is available.
     *
     * @return array<string,Dataset>
     *
     * @throws UnknownDataset
     * @throws ConnectionException
     */
    public function all(): array
    {
        $datasets = [];
        foreach ($this->names() as $name) {
            $datasets[$name] = $this->get($name);
        }

        return $datasets;
    }

    /**
     * @throws UnknownDataset
     */
    private function resolve(string $dataset): Dataset
    {
        $instance = class_exists($dataset) ? $this->container->get($dataset) : null;

        if (!$instance instanceof Dataset) {
            throw UnknownDataset::notADataset($dataset);
        }

        return $instance;
    }
}
