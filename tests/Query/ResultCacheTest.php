<?php

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Panorama;
use Aldeebhasan\Panorama\Tests\Reports\AllOrders;
use Aldeebhasan\Panorama\Tests\Reports\RevenueByCustomer;

function cachedOrders(): AllOrders
{
    return new class extends AllOrders
    {
        protected string $key = 'cached-orders';
        protected int $cacheFor = 60;
    };
}

function orders(int ...$ids): void
{
    app(Datasets::class)->fake('orders', array_map(
        static fn(int $id): array => ['id' => $id, 'customer_id' => 'c-1', 'amount' => 10, 'created_at' => '2026-01-15'],
        $ids,
    ));
}

function ids(array $rows): array
{
    return array_column($rows, 'id');
}

it('serves the same run from the cache', function (): void {
    $panorama = app(Panorama::class)->register(cachedOrders());

    orders(1, 2);
    expect(ids($panorama->run('cached-orders')->get()))->toBe([1, 2]);

    orders(3);
    expect(ids($panorama->run('cached-orders')->get()))->toBe([1, 2])
        ->and($panorama->run('cached-orders')->columns())->toHaveCount(4);
});

it('reads the datasets again once forgotten', function (): void {
    $panorama = app(Panorama::class)->register(cachedOrders());

    orders(1);
    $panorama->run('cached-orders')->get();

    orders(2);
    $panorama->forget('cached-orders');

    expect(ids($panorama->run('cached-orders')->get()))->toBe([2]);
});

it('caches nothing for a report that does not ask', function (): void {
    orders(1);
    app(Panorama::class)->run(AllOrders::class)->get();

    orders(2);
    expect(ids(app(Panorama::class)->run(AllOrders::class)->get()))->toBe([2]);
});

it('keeps each set of parameters apart', function (): void {
    $panorama = app(Panorama::class)->register(new class extends RevenueByCustomer
    {
        protected int $cacheFor = 60;
    });
    app(Datasets::class)->fake('crm_customers', [['ext_id' => 'c-1', 'custName' => 'Ada', 'country' => 'DE']]);
    orders(1);

    $september = $panorama->run('revenue-by-customer', ['from' => '2026-09-01', 'to' => '2026-09-30'])->get();
    orders(1, 2);
    $january = $panorama->run('revenue-by-customer', ['from' => '2026-01-01', 'to' => '2026-01-31'])->get();

    expect($september[0]['revenue'])->toBe(10.0)
        ->and($january[0]['revenue'])->toBe(20.0);
});

it('caches nothing when the rows were not read to the end', function (): void {
    $panorama = app(Panorama::class)->register(cachedOrders());

    orders(1, 2);
    $panorama->run('cached-orders')->result()->first();

    orders(3);
    expect(ids($panorama->run('cached-orders')->get()))->toBe([3]);
});

it('streams a result longer than max_rows without caching it', function (): void {
    config()->set('panorama.cache.max_rows', 1);
    app()->forgetInstance(Aldeebhasan\Panorama\Query\ResultCache::class);
    $panorama = app(Panorama::class)->register(cachedOrders());

    orders(1, 2);
    expect(ids($panorama->run('cached-orders')->get()))->toBe([1, 2]);

    orders(3);
    expect(ids($panorama->run('cached-orders')->get()))->toBe([3]);
});
