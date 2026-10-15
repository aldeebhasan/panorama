<?php

namespace Aldeebhasan\Panorama\Tests\Datasets;

use Aldeebhasan\Panorama\Dataset\Dataset;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Schema\Schema;
use Aldeebhasan\Panorama\Tests\TestCase;

/**
 * Fixture rows standing in for an application table, so the dataset layer can
 * be exercised without a database.
 */
class Orders extends Dataset
{
    public string $name = 'orders';

    public function parameters(): array
    {
        return ['from' => ['date']];
    }

    public function fetch(Params $params): iterable
    {
        $from = $params->string('from');

        foreach (TestCase::ORDERS as $row) {
            // Pushdown: the filter narrows the fetch, not the result.
            if ($from !== null && $row['created_at'] < $from) {
                continue;
            }

            yield $row;
        }
    }

    public function schema(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->string('customer_id')->references('crm_customers.id')
            ->decimal('amount', 2)->currency('USD')
            ->date('created_at');
    }
}
