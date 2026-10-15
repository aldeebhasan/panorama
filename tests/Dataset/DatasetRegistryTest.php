<?php

use Aldeebhasan\Panorama\Dataset\Dataset;
use Aldeebhasan\Panorama\Dataset\DatasetRegistry;
use Aldeebhasan\Panorama\Dataset\FakeDataset;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Exceptions\UnknownDataset;
use Aldeebhasan\Panorama\Schema\Schema;
use Aldeebhasan\Panorama\Tests\Datasets\CrmCustomers;
use Aldeebhasan\Panorama\Tests\Datasets\Orders;

it('registers the datasets declared in the config', function (): void {
    expect(app(DatasetRegistry::class)->names())->toEqualCanonicalizing([
        'orders',
        'crm_customers',
        'db_orders',
        'shop_a_sales',
        'shop_b_sales',
        'shop_c_sales',
    ]);
});

it('looks a dataset up by name, never by class name', function (): void {
    expect(app(DatasetRegistry::class)->get('orders'))->toBeInstanceOf(Orders::class)
        ->and(app(DatasetRegistry::class)->has('orders'))->toBeTrue()
        ->and(app(DatasetRegistry::class)->has('nope'))->toBeFalse();
});

it('hands out a fresh instance per lookup', function (): void {
    $registry = app(DatasetRegistry::class);

    expect($registry->get('orders'))->not->toBe($registry->get('orders'));
});

it('registers a dataset that serves its own rows', function (): void {
    $registry = app(DatasetRegistry::class);
    $registry->register(new FakeDataset('standalone', Schema::make()->int('id'), [['id' => 1]]));

    expect(iterator_to_array($registry->get('standalone')->rows(Params::make())))->toBe([['id' => 1]]);
});

it('registers a ready made instance', function (): void {
    $registry = app(DatasetRegistry::class);
    $registry->register(new CrmCustomers);

    expect($registry->get('crm_customers'))->toBeInstanceOf(CrmCustomers::class);
});

it('names the known datasets when one is missing', function (): void {
    app(DatasetRegistry::class)->get('ordrs');
})->throws(
    UnknownDataset::class,
    'No dataset is registered under the name [ordrs]. Known datasets are '
    . '[crm_customers, db_orders, orders, shop_a_sales, shop_b_sales, shop_c_sales].',
);

it('rejects a class that is not a dataset', function (): void {
    app(DatasetRegistry::class)->register(stdClass::class);
})->throws(UnknownDataset::class, 'does not extend');

it('discovers datasets in a directory', function (): void {
    $registry = new DatasetRegistry(app());

    $registry->discover(__DIR__ . '/../Datasets', 'Aldeebhasan\Panorama\Tests\Datasets');

    // Every dataset class in the directory, found without being listed.
    expect($registry->names())->toEqualCanonicalizing(app(DatasetRegistry::class)->names());
});

it('ignores a discovery path that does not exist', function (): void {
    $registry = new DatasetRegistry(app());

    $registry->discover('/nope/not/here', 'Nope');

    expect($registry->names())->toBe([]);
});

it('replaces a dataset with fixtures, keeping its declared schema', function (): void {
    $registry = app(DatasetRegistry::class);

    $registry->fake('crm_customers', [
        ['ext_id' => 'c-9', 'custName' => 'Fixture', 'country' => 'NL'],
    ]);

    $rows = iterator_to_array($registry->get('crm_customers')->rows(Params::make()));

    // Mapped and normalized by the real schema, so a bad fake still fails.
    expect($rows)->toBe([['id' => 'c-9', 'name' => 'Fixture', 'country' => 'NL']]);
});

it('accepts an explicit schema when faking a dataset that is not registered', function (): void {
    $registry = app(DatasetRegistry::class);

    $registry->fake('invented', [['id' => '5']], Schema::make()->int('id'));

    expect(iterator_to_array($registry->get('invented')->rows(Params::make())))->toBe([['id' => 5]]);
});

it('lets a fake be iterated more than once', function (): void {
    $generator = (function (): Generator {
        yield ['id' => 1];
    })();

    $dataset = new FakeDataset('once', Schema::make()->int('id'), $generator);

    expect(iterator_to_array($dataset->rows(Params::make())))->toBe([['id' => 1]])
        ->and(iterator_to_array($dataset->rows(Params::make())))->toBe([['id' => 1]]);
});

it('describes every registered dataset at once', function (): void {
    $all = app(DatasetRegistry::class)->all();

    expect($all)->toHaveKeys(['orders', 'crm_customers', 'db_orders'])
        ->and($all['orders'])->toBeInstanceOf(Dataset::class);
});
