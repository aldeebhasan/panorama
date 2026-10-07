<?php

namespace Aldeebhasan\Panorama\Dataset;

use Aldeebhasan\Panorama\Connection\ArrayConnection;
use Aldeebhasan\Panorama\Connection\ConnectionContract;
use Aldeebhasan\Panorama\Connection\DatabaseConnection;
use Aldeebhasan\Panorama\Connection\HttpConnection;
use Aldeebhasan\Panorama\Exceptions\ConnectionException;
use Aldeebhasan\Panorama\Exceptions\PanoramaException;
use Aldeebhasan\Panorama\Exceptions\SourceUnavailable;
use Aldeebhasan\Panorama\Schema\Processor;
use Aldeebhasan\Panorama\Schema\Schema;
use Aldeebhasan\Panorama\Schema\ViolationLog;
use Generator;
use Throwable;

/**
 * What data to pull from a connection, plus the shape it guarantees.
 *
 * A dataset is the reusable unit: it is defined once and then used standalone
 * or merged with any other dataset. Consumers never see raw rows, only the
 * normalized ones {@see rows()} yields.
 */
abstract class Dataset
{
    /**
     * The stable identifier, used as the table name in a workspace. Defaults
     * to the snake-cased class basename.
     */
    public string $name = '';

    /**
     * The name of the connection in config this dataset reads from.
     */
    public string $connection = '';

    private ?ConnectionContract $resolved = null;
    private ?Processor $processor = null;

    /**
     * Pull the raw rows. Push filters down to the source wherever the source
     * can apply them, so a date range narrows the fetch instead of the result.
     *
     * @return iterable<array-key,mixed>
     */
    abstract public function fetch(Params $params): iterable;

    /**
     * The contract every row this dataset yields honours.
     */
    abstract public function schema(): Schema;

    /**
     * The filters this dataset accepts, as validation rules. An empty list
     * means every filter is passed through untouched.
     *
     * @return array<string,mixed>
     */
    public function parameters(): array
    {
        return [];
    }

    public function name(): string
    {
        if ($this->name !== '') {
            return $this->name;
        }

        $basename = basename(str_replace('\\', '/', static::class));

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $basename));
    }

    public function connectionName(): string
    {
        return $this->connection;
    }

    /**
     * Attach the connection this dataset named. The registry does this when it
     * hands out an instance.
     */
    public function withConnection(ConnectionContract $connection): static
    {
        $this->resolved = $connection;

        return $this;
    }

    /**
     * @throws ConnectionException
     */
    public function connection(): ConnectionContract
    {
        return $this->resolved ?? throw ConnectionException::noConnection($this->name());
    }

    /**
     * Normalized rows, one at a time.
     *
     * @return Generator<int,array<string,mixed>>
     *
     * @throws PanoramaException
     */
    public function rows(Params $params): Generator
    {
        $processor = $this->processor();

        try {
            yield from $processor->process($this->fetch($this->accepted($params)));
        } catch (PanoramaException $known) {
            // Schema violations already name the dataset and the field.
            throw $known;
        } catch (Throwable $failure) {
            throw SourceUnavailable::fetching($this->name(), $failure);
        }
    }

    /**
     * The processor behind {@see rows()}, built once so its log survives the
     * iteration.
     */
    public function processor(): Processor
    {
        return $this->processor ??= new Processor($this->schema(), $this->name());
    }

    /**
     * What the last pass over this dataset dropped or patched.
     */
    public function log(): ViolationLog
    {
        return $this->processor()->log();
    }

    /**
     * The subset of the run's filters this dataset declared an interest in.
     */
    protected function accepted(Params $params): Params
    {
        $declared = array_keys($this->parameters());

        return $declared === [] ? $params : $params->only($declared);
    }

    /**
     * @throws ConnectionException
     */
    protected function query(): DatabaseConnection
    {
        return $this->expect(DatabaseConnection::class, 'database');
    }

    /**
     * @throws ConnectionException
     */
    protected function http(): HttpConnection
    {
        return $this->expect(HttpConnection::class, 'http');
    }

    /**
     * @throws ConnectionException
     */
    protected function fixtures(): ArrayConnection
    {
        return $this->expect(ArrayConnection::class, 'array');
    }

    /**
     * Narrow the attached connection to the kind this dataset asked for, and
     * say plainly what it got instead when it is the wrong one.
     *
     * @template T of ConnectionContract
     *
     * @param class-string<T> $kind
     * @return T
     *
     * @throws ConnectionException
     */
    private function expect(string $kind, string $driver): ConnectionContract
    {
        $connection = $this->connection();

        if (!$connection instanceof $kind) {
            throw ConnectionException::wrongKind(
                $this->name(),
                $connection->name(),
                $connection->driver(),
                $driver,
            );
        }

        return $connection;
    }
}
