<?php

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Exceptions\RenderException;
use Aldeebhasan\Panorama\Exceptions\ReportingException;
use Aldeebhasan\Panorama\Panorama;
use Aldeebhasan\Panorama\Query\DatasetQuery;
use Aldeebhasan\Panorama\Report\Column;
use Aldeebhasan\Panorama\Report\Report;
use Aldeebhasan\Panorama\Tests\Reports\AllOrders;
use Aldeebhasan\Panorama\Tests\Reports\RevenueByCustomer;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    app(Datasets::class)->fake('crm_customers', [
        ['ext_id' => 'c-1', 'custName' => 'Ada', 'country' => 'DE'],
        ['ext_id' => 'c-2', 'custName' => 'Linus', 'country' => null],
    ]);
});

/**
 * @return array<string,mixed>
 */
function dateRange(): array
{
    return ['from' => '2026-01-01', 'to' => '2026-12-31'];
}

/*
|--------------------------------------------------------------------------
| the registry
|--------------------------------------------------------------------------
*/

it('registers the reports declared in the config', function (): void {
    expect(app(Panorama::class)->keys())
        ->toEqualCanonicalizing(['revenue-by-customer', 'all-orders', 'finance-only'])
        ->and(app(Panorama::class)->all()['revenue-by-customer'])->toBe('Revenue by Customer');
});

it('derives the key and the name from the class name', function (): void {
    $report = new AllOrders;

    expect($report->key())->toBe('all-orders')
        ->and($report->name())->toBe('All Orders');
});

it('hands out a fresh report instance per lookup', function (): void {
    $panorama = app(Panorama::class);

    expect($panorama->report('all-orders'))->not->toBe($panorama->report('all-orders'))
        ->and($panorama->has('all-orders'))->toBeTrue()
        ->and($panorama->has('nope'))->toBeFalse();
});

it('names the known reports when one is missing', function (): void {
    app(Panorama::class)->report('revenue');
})->throws(
    ReportingException::class,
    'No report registered under the key [revenue]. Known reports are [all-orders, finance-only, revenue-by-customer].',
);

it('rejects a class that is not a report', function (): void {
    app(Panorama::class)->register(stdClass::class);
})->throws(ReportingException::class, 'must implement');

it('runs a report class that was never registered', function (): void {
    $run = app(Panorama::class)->run(RevenueByCustomer::class, dateRange());

    expect($run->report())->toBeInstanceOf(RevenueByCustomer::class);
});

it('runs a ready made report instance', function (): void {
    $report = new class extends Report
    {
        protected string $key = 'inline';

        public function query(Datasets $datasets): DatasetQuery
        {
            return $datasets->from('orders')->orderBy('orders.id')->limit(1);
        }
    };

    expect(app(Panorama::class)->run($report)->get())->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| parameters and authorization
|--------------------------------------------------------------------------
*/

it('validates the parameters against the rules the report declares', function (): void {
    app(Panorama::class)->run('revenue-by-customer', ['from' => 'not a date']);
})->throws(ValidationException::class);

it('rejects a range whose end is before its start', function (): void {
    app(Panorama::class)->run('revenue-by-customer', ['from' => '2026-06-01', 'to' => '2026-01-01']);
})->throws(ValidationException::class);

it('narrows the parameters to the declared ones', function (): void {
    $run = app(Panorama::class)->run('revenue-by-customer', dateRange() + ['secret' => 'x']);

    expect($run->params()->all())->toBe(dateRange());
});

it('passes everything through when a report declares no parameters', function (): void {
    $run = app(Panorama::class)->run('all-orders', ['anything' => 1]);

    expect($run->params()->all())->toBe(['anything' => 1]);
});

it('runs a report for a user it authorizes', function (): void {
    $run = app(Panorama::class)->run('finance-only', [], ['can' => ['view-finance-reports']]);

    expect($run->get())->toHaveCount(3);
});

it('refuses a report for a user it does not authorize', function (): void {
    app(Panorama::class)->run('finance-only', [], ['can' => []]);
})->throws(ReportingException::class, 'not allowed to run the report [finance-only]');

it('skips authorization when no user is given', function (): void {
    expect(app(Panorama::class)->run('finance-only')->get())->toHaveCount(3);
});

/*
|--------------------------------------------------------------------------
| rows and columns
|--------------------------------------------------------------------------
*/

it('runs the acceptance case: two sources merged and aggregated', function (): void {
    $rows = app(Panorama::class)->run('revenue-by-customer', dateRange())->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['name'])->toBe('Ada')
        ->and($rows[0]['revenue'])->toBe(49.99)
        ->and($rows[1]['name'])->toBe('Linus')
        ->and($rows[1]['revenue'])->toBe(5.5);
});

it('executes the query only once per run', function (): void {
    $run = app(Panorama::class)->run('all-orders');

    expect($run->result())->toBe($run->result());
});

it('does nothing until the rows are asked for', function (): void {
    $run = app(Panorama::class)->run('all-orders');

    expect($run->report()->key())->toBe('all-orders')
        ->and($run->query())->toBeInstanceOf(DatasetQuery::class);
});

it('uses the columns the report declared', function (): void {
    $columns = app(Panorama::class)->run('revenue-by-customer', dateRange())->columns();

    expect($columns)->toHaveCount(2)
        ->and($columns[0]->heading())->toBe('Customer')
        ->and($columns[1]->heading())->toBe('Revenue');
});

it('falls back to the columns the query produced', function (): void {
    $columns = app(Panorama::class)->run('all-orders')->columns();

    expect(array_map(fn(Column $c): string => $c->name, $columns))
        ->toBe(['id', 'customer_id', 'amount', 'created_at']);
});

it('streams the rows', function (): void {
    $run = app(Panorama::class)->run('all-orders');

    foreach ($run->rows() as $row) {
        expect($row['id'])->toEqual(1);

        break;
    }
});

/*
|--------------------------------------------------------------------------
| formats
|--------------------------------------------------------------------------
*/

it('refuses a format it has no renderer for', function (): void {
    app(Panorama::class)->run('all-orders')->render('xlsx');
})->throws(RenderException::class, 'No renderer is registered for [xlsx]. Available formats are [csv, html, json]');
