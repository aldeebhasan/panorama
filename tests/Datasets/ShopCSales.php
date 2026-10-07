<?php

namespace Aldeebhasan\Panorama\Tests\Datasets;

use Aldeebhasan\Panorama\Dataset\Dataset;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Schema\Schema;

/**
 * Deliberately the wrong shape for a union with the other two shops: it drops
 * `units` and types `revenue` as a string.
 */
class ShopCSales extends Dataset
{
    public string $name = 'shop_c_sales';
    public string $connection = '';

    public function fetch(Params $params): iterable
    {
        return [['sku' => 'C-1', 'revenue' => '9.00', 'currency' => 'EUR']];
    }

    public function schema(): Schema
    {
        return Schema::make()
            ->primaryKey('sku')
            ->string('sku')
            ->string('revenue')
            ->string('currency');
    }
}
