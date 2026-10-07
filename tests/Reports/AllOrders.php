<?php

namespace Aldeebhasan\Panorama\Tests\Reports;

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Query\Merge;
use Aldeebhasan\Panorama\Report\Report;

/**
 * No declared columns and no parameters, so the rendered output falls back to
 * whatever the query produced.
 */
class AllOrders extends Report
{
    public function query(Datasets $datasets): Merge
    {
        return $datasets->from('orders')->orderBy('orders.id');
    }
}
