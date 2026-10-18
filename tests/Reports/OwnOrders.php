<?php

namespace Aldeebhasan\Panorama\Tests\Reports;

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Query\DatasetQuery;
use Aldeebhasan\Panorama\Report\Report;
use Aldeebhasan\Panorama\Tests\Fixtures\User;

/**
 * Not registered, so it is queued by class, and authorized against a model,
 * so a permission revoked while the job waits can be seen in the worker.
 */
class OwnOrders extends Report
{
    public function query(Datasets $datasets): DatasetQuery
    {
        return $datasets->from('orders')->orderBy('orders.id');
    }

    public function authorize(mixed $user = null): bool
    {
        return $user instanceof User && in_array('orders', $user->abilities, true);
    }
}
