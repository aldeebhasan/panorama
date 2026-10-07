<?php

namespace Aldeebhasan\Panorama\Tests\Datasets;

use Aldeebhasan\Panorama\Dataset\Dataset;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Schema\Schema;

/**
 * Reads a real table through the application's own connection.
 */
class DbOrders extends Dataset
{
    public string $name = 'db_orders';
    public string $connection = 'default';

    public function fetch(Params $params): iterable
    {
        return $this->query()
            ->table('db_orders')
            ->when($params->string('from'), fn($query, $from) => $query->where('created_at', '>=', $from))
            ->orderBy('id')
            ->cursor();
    }

    public function schema(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->string('customer_id')
            ->decimal('amount', 2)
            ->date('created_at');
    }
}
