<?php

namespace Aldeebhasan\Panorama\Tests\Datasets;

use Aldeebhasan\Panorama\Dataset\Dataset;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Schema\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Reads a real table through the application's own database.
 */
class DbOrders extends Dataset
{
    public string $name = 'db_orders';

    public function fetch(Params $params): iterable
    {
        return DB::table('db_orders')
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
