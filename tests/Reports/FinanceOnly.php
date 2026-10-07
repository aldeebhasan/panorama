<?php

namespace Aldeebhasan\Panorama\Tests\Reports;

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Query\Merge;
use Aldeebhasan\Panorama\Report\Report;

class FinanceOnly extends Report
{
    protected string $key = 'finance-only';

    public function query(Datasets $datasets): Merge
    {
        return $datasets->from('orders');
    }

    public function authorize(mixed $user = null): bool
    {
        return is_array($user) && in_array('view-finance-reports', $user['can'] ?? [], true);
    }
}
