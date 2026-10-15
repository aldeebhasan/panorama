<?php

namespace Aldeebhasan\Panorama\Tests\Datasets;

use Aldeebhasan\Panorama\Dataset\Dataset;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Schema\Schema;

class ShopASales extends Dataset
{
    public string $name = 'shop_a_sales';

    public function fetch(Params $params): iterable
    {
        return [
            ['sku' => 'A-1', 'units' => 2, 'revenue' => '10.00'],
            ['sku' => 'A-2', 'units' => 1, 'revenue' => '5.00'],
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
