<?php

use Aldeebhasan\Panorama\Connection\ArrayConnection;
use Aldeebhasan\Panorama\Dataset\Dataset;
use Aldeebhasan\Panorama\Dataset\DatasetRegistry;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Exceptions\ConnectionException;
use Aldeebhasan\Panorama\Exceptions\SchemaViolation;
use Aldeebhasan\Panorama\Exceptions\SourceUnavailable;
use Aldeebhasan\Panorama\Schema\Schema;
use Aldeebhasan\Panorama\Tests\Datasets\CrmCustomers;
use Aldeebhasan\Panorama\Tests\Datasets\Orders;
use Illuminate\Support\Facades\Http;

function datasets(): DatasetRegistry
{
    return app(DatasetRegistry::class);
}

/*
|--------------------------------------------------------------------------
| identity and declarations
|--------------------------------------------------------------------------
*/

it('derives the dataset name from the class when none is declared', function (): void {
    $dataset = new class extends Dataset
    {
        public function fetch(Params $params): iterable
        {
            return [];
        }

        public function schema(): Schema
        {
            return Schema::make()->int('id');
        }
    };

    // An anonymous class basename is noisy, so assert the conversion directly.
    expect((new CrmCustomers)->name())->toBe('crm_customers')
        ->and($dataset->name())->toBeString();
});

it('keeps the declared name and connection', function (): void {
    $orders = new Orders;

    expect($orders->name())->toBe('orders')
        ->and($orders->connectionName())->toBe('fixtures');
});

/*
|--------------------------------------------------------------------------
| normalized rows
|--------------------------------------------------------------------------
*/

it('yields rows that honour the schema', function (): void {
    $rows = iterator_to_array(datasets()->get('orders')->rows(Params::make()));

    expect($rows)->toBe([
        ['id' => 1, 'customer_id' => 'c-1', 'amount' => 19.99, 'created_at' => '2026-01-15'],
        ['id' => 2, 'customer_id' => 'c-2', 'amount' => 5.5, 'created_at' => '2026-02-20'],
        ['id' => 3, 'customer_id' => 'c-1', 'amount' => 30.0, 'created_at' => '2026-03-05'],
    ]);
});

it('pushes the declared filters down to the fetch', function (): void {
    $rows = iterator_to_array(datasets()->get('orders')->rows(Params::make(['from' => '2026-02-01'])));

    expect(array_column($rows, 'id'))->toBe([2, 3]);
});

it('hides filters the dataset did not declare', function (): void {
    $dataset = new class extends Dataset
    {
        /** @var array<string,mixed> */
        public array $seen = [];

        public function parameters(): array
        {
            return ['from' => ['date']];
        }

        public function fetch(Params $params): iterable
        {
            $this->seen = $params->all();

            return [];
        }

        public function schema(): Schema
        {
            return Schema::make()->int('id');
        }
    };

    iterator_to_array($dataset->rows(Params::make(['from' => '2026-01-01', 'secret' => 'x'])));

    expect($dataset->seen)->toBe(['from' => '2026-01-01']);
});

it('passes every filter through when none are declared', function (): void {
    $dataset = new class extends Dataset
    {
        /** @var array<string,mixed> */
        public array $seen = [];

        public function fetch(Params $params): iterable
        {
            $this->seen = $params->all();

            return [];
        }

        public function schema(): Schema
        {
            return Schema::make()->int('id');
        }
    };

    iterator_to_array($dataset->rows(Params::make(['anything' => 1])));

    expect($dataset->seen)->toBe(['anything' => 1]);
});

it('reads rows one at a time', function (): void {
    $rows = datasets()->get('orders')->rows(Params::make());

    expect($rows->current()['id'])->toBe(1);
});

it('exposes the violation log of its own run', function (): void {
    $connection = new ArrayConnection('fixtures', [
        'orders' => [
            ['id' => 1, 'customer_id' => 'c-1', 'amount' => 'not a number', 'created_at' => '2026-01-15'],
            ['id' => 2, 'customer_id' => 'c-2', 'amount' => '5.50', 'created_at' => '2026-02-20'],
        ],
    ]);

    $dataset = new class extends Orders
    {
        public function schema(): Schema
        {
            return parent::schema()->skipOnViolation();
        }
    };

    $rows = iterator_to_array($dataset->withConnection($connection)->rows(Params::make()));

    expect($rows)->toHaveCount(1)
        ->and($dataset->log()->skipped())->toBe(1)
        ->and($dataset->log()->accepted())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| failure modes
|--------------------------------------------------------------------------
*/

it('fails plainly when no connection is attached', function (): void {
    iterator_to_array((new Orders)->rows(Params::make()));
})->throws(ConnectionException::class, 'Dataset [orders] has no connection');

it('says what kind of connection it got when the driver is wrong', function (): void {
    $orders = (new Orders)->withConnection(new ArrayConnection('fixtures'));
    $wrong = new class extends Orders
    {
        public function fetch(Params $params): iterable
        {
            return $this->http()->get('/orders');
        }
    };

    iterator_to_array($wrong->withConnection($orders->connection())->rows(Params::make()));
})->throws(
    ConnectionException::class,
    'Dataset [orders] asked for a http connection but [fixtures] is a array connection.',
);

it('wraps a failing source in source unavailable', function (): void {
    $dataset = new class extends Dataset
    {
        public string $name = 'flaky';

        public function fetch(Params $params): iterable
        {
            throw new RuntimeException('the api is down');
        }

        public function schema(): Schema
        {
            return Schema::make()->int('id');
        }
    };

    try {
        iterator_to_array($dataset->rows(Params::make()));
    } catch (SourceUnavailable $e) {
        expect($e->dataset)->toBe('flaky')
            ->and($e->getMessage())->toBe('Dataset [flaky] could not be fetched: the api is down')
            ->and($e->getPrevious())->toBeInstanceOf(RuntimeException::class);

        return;
    }

    $this->fail('the failure was not wrapped');
});

it('lets a schema violation through rather than relabelling it', function (): void {
    $connection = new ArrayConnection('fixtures', [
        'orders' => [['id' => 'abc', 'customer_id' => 'c-1', 'amount' => '1.00', 'created_at' => '2026-01-15']],
    ]);

    iterator_to_array((new Orders)->withConnection($connection)->rows(Params::make()));
})->throws(SchemaViolation::class, 'Dataset [orders] field [id] on row 1');

/*
|--------------------------------------------------------------------------
| real sources
|--------------------------------------------------------------------------
*/

it('reads a database source through the application connection', function (): void {
    $this->createOrdersTable();

    $rows = iterator_to_array(datasets()->get('db_orders')->rows(Params::make()));

    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toBe([
            'id' => 1,
            'customer_id' => 'c-1',
            'amount' => 19.99,
            'created_at' => '2026-01-15',
        ]);
});

it('filters a database source at the source', function (): void {
    $this->createOrdersTable();

    $rows = iterator_to_array(datasets()->get('db_orders')->rows(Params::make(['from' => '2026-03-01'])));

    expect(array_column($rows, 'id'))->toBe([3]);
});

it('walks a paginated http source and maps its field names', function (): void {
    Http::fake([
        'crm.test/customers?*page=1*' => Http::response([
            'data' => [
                ['ext_id' => 'c-1', 'custName' => ' Ada ', 'country' => 'DE'],
                ['ext_id' => 'c-2', 'custName' => 'Linus', 'country' => null],
            ],
        ]),
        'crm.test/customers?*page=2*' => Http::response([
            'data' => [
                ['ext_id' => 'c-3', 'custName' => 'Grace', 'country' => 'US'],
            ],
        ]),
    ]);

    $rows = iterator_to_array(datasets()->get('crm_customers')->rows(Params::make()));

    expect($rows)->toBe([
        ['id' => 'c-1', 'name' => 'Ada', 'country' => 'DE'],
        ['id' => 'c-2', 'name' => 'Linus', 'country' => null],
        ['id' => 'c-3', 'name' => 'Grace', 'country' => 'US'],
    ]);

    // A short page is the last page, so page 3 is never requested.
    Http::assertSentCount(2);
});

it('turns a failing http status into source unavailable', function (): void {
    Http::fake(['crm.test/*' => Http::response(status: 503)]);

    iterator_to_array(datasets()->get('crm_customers')->rows(Params::make()));
})->throws(SourceUnavailable::class, 'Dataset [crm_customers] could not be fetched');
