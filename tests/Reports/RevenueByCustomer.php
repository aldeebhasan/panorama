<?php

namespace Aldeebhasan\Panorama\Tests\Reports;

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Query\Aggregate;
use Aldeebhasan\Panorama\Query\DatasetQuery;
use Aldeebhasan\Panorama\Report\Column;
use Aldeebhasan\Panorama\Report\Report;

/**
 * The design document's own example, and the acceptance test: a dataset of
 * orders merged with one read over HTTP, aggregated and exported.
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

    public function query(Datasets $datasets): DatasetQuery
    {
        return $datasets->from('orders')
            ->join('crm_customers')
            ->groupBy('crm_customers.name', ['revenue' => Aggregate::sum('orders.amount')])
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
