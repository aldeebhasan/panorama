<?php

namespace Aldeebhasan\Panorama\Workspace;

use Aldeebhasan\Panorama\Dataset\Dataset;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Exceptions\QueryException;
use Aldeebhasan\Panorama\Query\Plan;
use Aldeebhasan\Panorama\Query\Result;
use Aldeebhasan\Panorama\Schema\Field;
use Aldeebhasan\Panorama\Schema\FieldType;
use Aldeebhasan\Panorama\Schema\Schema;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Throwable;

/**
 * Loads datasets into tables in a database the application already has, and
 * merges them with SQL.
 *
 * Temporary tables are the default: they are invisible to other sessions and
 * vanish when the connection closes, so a crashed run leaves nothing behind.
 * They need `CREATE TEMPORARY TABLE` and a connection held open for the whole
 * run, which some managed databases and pgbouncer in transaction-pooling mode
 * do not give; `temporary: false` then creates real prefixed tables and drops
 * them at the end instead.
 */
final class DatabaseWorkspace implements WorkspaceContract
{
    /** @var array<string,string> dataset name => physical table name */
    private array $tables = [];

    /** @var list<string> tables that could not be dropped */
    private array $undropped = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly SqlCompiler $compiler,
        private readonly string $prefix = 'panorama_',
        private readonly int $chunk = 500,
        private readonly bool $temporary = true,
    ) {
    }

    public function name(): string
    {
        return 'database';
    }

    public function load(Dataset $dataset, Params $params): int
    {
        $schema = $dataset->schema();
        $table = $this->prefix . $dataset->name();

        $this->createTable($table, $schema);

        // Recorded before a single row is streamed: the table exists from
        // here, so a dataset that fails half way must still be cleaned up.
        $this->tables[$dataset->name()] = $this->connection->getTablePrefix() . $table;

        $loaded = 0;
        $buffer = [];

        foreach ($dataset->rows($params) as $row) {
            $buffer[] = $this->encode($row, $schema);
            $loaded++;

            if (count($buffer) >= $this->chunk) {
                $this->connection->table($table)->insert($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $this->connection->table($table)->insert($buffer);
        }

        return $loaded;
    }

    /**
     * No fast path yet. Reading a source directly is what the `attach` stage
     * is for, and it arrives with the engines that can actually do it.
     */
    public function attach(Dataset $dataset): bool
    {
        return false;
    }

    /**
     * @throws QueryException
     */
    public function query(Plan $plan): Result
    {
        $compiled = $this->compiler->compile($plan, $this->tables);

        return new Result($this->stream($compiled));
    }

    public function drop(): void
    {
        $builder = $this->connection->getSchemaBuilder();

        foreach ($this->tables as $table) {
            try {
                $builder->drop($this->unprefixed($table));
            } catch (Throwable) {
                // Cleanup must never be the thing that fails a finished run: a
                // temporary table may already have gone with its connection.
                // It is recorded rather than swallowed, so a run log can say
                // so and a test can catch a drop that genuinely could not
                // happen.
                $this->undropped[] = $table;
            }
        }

        $this->tables = [];
    }

    /**
     * Tables this workspace could not drop. Empty after a clean run.
     *
     * @return list<string>
     */
    public function undropped(): array
    {
        return $this->undropped;
    }

    /**
     * The SQL this plan compiles to, for `--explain` and for tests.
     *
     * @throws QueryException
     */
    public function toSql(Plan $plan): Compiled
    {
        return $this->compiler->compile($plan, $this->tables);
    }

    /**
     * @return array<string,string>
     */
    public function tables(): array
    {
        return $this->tables;
    }

    /**
     * @return Generator<int,array<string,mixed>>
     */
    private function stream(Compiled $compiled): Generator
    {
        foreach ($this->connection->cursor($compiled->sql, $compiled->bindings) as $row) {
            $columns = [];
            foreach ((array) $row as $column => $value) {
                $columns[(string) $column] = $value;
            }

            yield $columns;
        }
    }

    private function createTable(string $table, Schema $schema): void
    {
        $builder = $this->connection->getSchemaBuilder();

        if (!$this->temporary) {
            $builder->dropIfExists($table);
        }

        $builder->create($table, function (Blueprint $blueprint) use ($schema): void {
            if ($this->temporary) {
                $blueprint->temporary();
            }

            foreach ($schema->fields() as $field) {
                $column = $this->column($blueprint, $field, $this->isIndexed($schema, $field));

                if ($field->nullable) {
                    $column->nullable();
                }
            }

            // Keys and relation fields are what joins read, so they are the
            // ones worth an index on a table that is written once and scanned.
            foreach ($this->indexed($schema) as $name) {
                $blueprint->index($name);
            }
        });
    }

    /**
     * @return \Illuminate\Database\Schema\ColumnDefinition
     */
    private function column(Blueprint $blueprint, Field $field, bool $indexed)
    {
        return match ($field->type) {
            // Indexed strings get a bounded length, because MySQL cannot index
            // a TEXT column without one; the rest stay unbounded.
            FieldType::String => $indexed
                ? $blueprint->string($field->name, 191)
                : $blueprint->text($field->name),
            FieldType::Enum => $blueprint->string($field->name, 191),
            FieldType::Int => $blueprint->bigInteger($field->name),
            FieldType::Decimal => $blueprint->decimal(
                $field->name,
                min(65, 20 + $field->scale),
                min(30, $field->scale),
            ),
            FieldType::Bool => $blueprint->boolean($field->name),
            FieldType::Date => $blueprint->date($field->name),
            FieldType::Datetime => $blueprint->dateTime($field->name),
            FieldType::Json => $blueprint->json($field->name),
        };
    }

    private function isIndexed(Schema $schema, Field $field): bool
    {
        return in_array($field->name, $this->indexed($schema), true);
    }

    /**
     * @return list<string>
     */
    private function indexed(Schema $schema): array
    {
        return array_values(array_unique(array_merge(
            $schema->keys(),
            array_keys($schema->relations()),
        )));
    }

    /**
     * Json has to be re-encoded on the way into the column; everything else
     * the schema produced is already a scalar the driver accepts.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function encode(array $row, Schema $schema): array
    {
        foreach ($schema->fields() as $name => $field) {
            if ($field->type === FieldType::Json && is_array($row[$name] ?? null)) {
                $row[$name] = json_encode($row[$name]);
            }
        }

        return $row;
    }

    private function unprefixed(string $table): string
    {
        $prefix = $this->connection->getTablePrefix();

        return $prefix !== '' && str_starts_with($table, $prefix)
            ? substr($table, strlen($prefix))
            : $table;
    }
}
