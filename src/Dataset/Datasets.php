<?php

namespace Aldeebhasan\Panorama\Dataset;

use Aldeebhasan\Panorama\Query\DatasetQuery;
use Aldeebhasan\Panorama\Query\Executor;
use Aldeebhasan\Panorama\Schema\Schema;

/**
 * The entry point to datasets and the queries reports build over them.
 *
 * Standalone:
 *
 *     Datasets::from('orders')->params(['from' => '2026-01-01'])->get();
 *
 * Joined through a declared relation, grouped and aggregated:
 *
 *     Datasets::from('orders')
 *         ->join('crm_customers')
 *         ->groupBy('crm_customers.name', ['revenue' => Aggregate::sum('orders.amount')])
 *         ->orderBy('revenue', 'desc')
 *         ->get();
 *
 * Appended:
 *
 *     Datasets::union('shop_a_sales', 'shop_b_sales')->tagSource('source')->get();
 */
final class Datasets
{
    public function __construct(
        private readonly DatasetRegistry $registry,
        private readonly Executor $executor,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    */

    public function from(string $dataset): DatasetQuery
    {
        return DatasetQuery::from($this->executor, $dataset);
    }

    public function union(string ...$datasets): DatasetQuery
    {
        return DatasetQuery::union($this->executor, ...$datasets);
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
