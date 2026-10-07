<?php

namespace Aldeebhasan\Panorama\Dataset;

use Aldeebhasan\Panorama\Query\Merge;
use Aldeebhasan\Panorama\Query\Query;
use Aldeebhasan\Panorama\Schema\Schema;
use Aldeebhasan\Panorama\Workspace\MergeRunner;

/**
 * The entry point to datasets and merges: sources are tables, and a report is
 * a query over them.
 *
 * Standalone:
 *
 *     Datasets::from('orders')->params(['from' => '2026-01-01'])->get();
 *
 * Merged through a declared relation:
 *
 *     Datasets::from('orders')
 *         ->join('crm_customers')
 *         ->select('crm_customers.name', 'sum(orders.amount) as revenue')
 *         ->groupBy('crm_customers.name')
 *         ->get();
 *
 * Appended:
 *
 *     Datasets::union('shop_a_sales', 'shop_b_sales')->tagSource('source')->get();
 *
 * Or hand written, for anything the builder does not cover:
 *
 *     Datasets::sql('select ... from orders o join crm_customers c on c.id = o.customer_id')->get();
 */
final class Datasets
{
    public function __construct(
        private readonly DatasetRegistry $registry,
        private readonly MergeRunner $runner,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Merges
    |--------------------------------------------------------------------------
    */

    public function from(string $dataset): Merge
    {
        return new Merge(Query::from($dataset), $this->runner);
    }

    public function union(string ...$datasets): Merge
    {
        return new Merge(Query::union(...$datasets), $this->runner);
    }

    /**
     * @param list<mixed> $bindings
     */
    public function sql(string $sql, array $bindings = []): Merge
    {
        return new Merge(Query::raw($sql, $bindings), $this->runner);
    }

    public function merge(Query $query): Merge
    {
        return new Merge($query, $this->runner);
    }

    /*
    |--------------------------------------------------------------------------
    | The registry
    |--------------------------------------------------------------------------
    */

    public function get(string $name): Dataset
    {
        return $this->registry->get($name);
    }

    public function has(string $name): bool
    {
        return $this->registry->has($name);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->registry->names();
    }

    /**
     * @return array<string,Dataset>
     */
    public function all(): array
    {
        return $this->registry->all();
    }

    /**
     * @param class-string<Dataset>|string|Dataset $dataset
     */
    public function register(string|Dataset $dataset): self
    {
        $this->registry->register($dataset);

        return $this;
    }

    /**
     * Replace a source with fixture rows, inside a test.
     *
     * @param iterable<array-key,mixed> $rows
     */
    public function fake(string $name, iterable $rows, ?Schema $schema = null): self
    {
        $this->registry->fake($name, $rows, $schema);

        return $this;
    }

    public function registry(): DatasetRegistry
    {
        return $this->registry;
    }
}
