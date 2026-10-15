<?php

use Aldeebhasan\Panorama\Dataset\DatasetRegistry;
use Illuminate\Support\Facades\Http;

it('lists the datasets with their keys and relations', function (): void {
    [$status, $output] = console('datasets:list');

    expect($status)->toBe(0)
        ->and($output)->toContain('orders')
        ->toContain('crm_customers')
        ->not->toContain('Connection')
        ->toContain('customer_id → crm_customers.id');
});

it('warns when no datasets are registered', function (): void {
    config()->set('panorama.datasets.register', []);
    app()->forgetInstance(DatasetRegistry::class);

    [$status, $output] = console('datasets:list');

    expect($status)->toBe(0)
        ->and($output)->toContain('No datasets registered');
});

it('describes a dataset schema', function (): void {
    [$status, $output] = console('datasets:describe', ['name' => 'orders']);

    expect($status)->toBe(0)
        ->and($output)->toContain('Primary key')
        ->toContain('fail')
        ->toContain('scale 2, USD')
        ->toContain('→ crm_customers.id')
        ->toContain('decimal')
        ->toContain('from')
        ->toContain('date');
});

it('shows the mapped raw name when a field is renamed', function (): void {
    [$status, $output] = console('datasets:describe', ['name' => 'crm_customers']);

    expect($status)->toBe(0)
        ->and($output)->toContain('ext_id')
        ->toContain('custName')
        ->toContain('yes');
});

it('samples rows when asked', function (): void {
    [$status, $output] = console('datasets:describe', ['name' => 'orders', '--sample' => '2']);

    expect($status)->toBe(0)
        ->and($output)->toContain('Sample (2 rows)')
        ->toContain('19.99')
        ->not->toContain('30');
});

it('reports the violations a sample ran into', function (): void {
    app(DatasetRegistry::class)->fake('crm_customers', [
        ['ext_id' => 'c-1', 'custName' => 'Ada', 'country' => 'DE'],
        ['ext_id' => null, 'custName' => 'Broken', 'country' => 'DE'],
    ], Aldeebhasan\Panorama\Schema\Schema::make()
        ->skipOnViolation()
        ->string('id', from: 'ext_id')
        ->string('name', from: 'custName'));

    [$status, $output] = console('datasets:describe', ['name' => 'crm_customers', '--sample' => '5']);

    expect($status)->toBe(0)
        ->and($output)->toContain('Sample (1 rows)')
        ->toContain('1 rows skipped, 0 values nullified');
});

it('fails to describe a dataset it does not know', function (): void {
    [$status, $output] = console('datasets:describe', ['name' => 'nope']);

    expect($status)->toBe(1)
        ->and($output)->toContain('No dataset is registered under the name [nope]');
});

it('reports a sample that cannot reach its source', function (): void {
    Http::fake(['crm.test/*' => Http::response(status: 500)]);

    [$status, $output] = console('datasets:describe', ['name' => 'crm_customers', '--sample' => '1']);

    expect($status)->toBe(1)
        ->and($output)->toContain('could not be fetched');
});

it('generates a dataset class', function (): void {
    $path = app_path('Reports/Datasets/CrmContacts.php');

    try {
        [$status] = console('make:dataset', ['name' => 'CrmContacts']);

        expect($status)->toBe(0)
            ->and(file_exists($path))->toBeTrue();

        expect((string) file_get_contents($path))
            ->toContain('namespace App\Reports\Datasets;')
            ->toContain('class CrmContacts extends Dataset')
            ->toContain("public string \$name = 'crm_contacts';")
            ->toContain("DB::table('crm_contacts')");
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});
