<?php

namespace Aldeebhasan\Panorama\Tests\Datasets;

use Aldeebhasan\Panorama\Dataset\Dataset;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Schema\Schema;

class ShopBSales extends Dataset
{
    public string $name = 'shop_b_sales';

    public function fetch(Params $params): iterable
    {
        return [
            ['sku' => 'B-1', 'units' => 7, 'revenue' => '70.00'],
        ];
    }

    public function schema(): Schema
    {
        return Schema::make()
            ->primaryKey('sku')
            ->string('sku')
            ->int('units')
            ->decimal('revenue', 2);
    }
}
