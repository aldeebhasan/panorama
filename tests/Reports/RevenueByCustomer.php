<?php

namespace Aldeebhasan\Panorama\Tests\Reports;

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Query\Merge;
use Aldeebhasan\Panorama\Report\Column;
use Aldeebhasan\Panorama\Report\Report;

/**
 * The design document's own example, and the MVP's acceptance test: a database
 * dataset merged with an HTTP one, aggregated and exported.
 */
class RevenueByCustomer extends Report
{
    protected string $key = 'revenue-by-customer';
    protected string $name = 'Revenue by Customer';

    public function parameters(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
        ];
    }

    public function query(Datasets $datasets): Merge
    {
        return $datasets->from('orders')
            ->join('crm_customers')
            ->select('crm_customers.name', 'sum(orders.amount) as revenue')
            ->groupBy('crm_customers.name')
            ->orderBy('name');
    }

    public function columns(): array
    {
        return [
            Column::make('name')->label('Customer'),
            Column::make('revenue')->money('USD')->total(),
        ];
    }
}
