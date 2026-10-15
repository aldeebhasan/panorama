<?php

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Panorama;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    app(Datasets::class)->fake('crm_customers', [
        ['ext_id' => 'c-1', 'custName' => 'Ada', 'country' => 'DE'],
        ['ext_id' => 'c-2', 'custName' => 'Linus', 'country' => null],
    ]);
});

it('lists the reports with their parameters', function (): void {
    [$status, $output] = console('reports:list');

    expect($status)->toBe(0)
        ->and($output)->toContain('revenue-by-customer')
        ->toContain('Revenue by Customer')
        ->toContain('from, to')
        ->toContain('all-orders')
        ->toContain('orders, crm_customers');
});

it('warns when no reports are registered', function (): void {
    config()->set('panorama.reports', []);
    app()->forgetInstance(Panorama::class);

    [$status, $output] = console('reports:list');

    expect($status)->toBe(0)
        ->and($output)->toContain('No reports registered');
});

it('runs a report and prints it as json by default', function (): void {
    [$status, $output] = console('reports:run', [
        'report' => 'revenue-by-customer',
        '--param' => ['from=2026-01-01', 'to=2026-12-31'],
    ]);

    $rows = json_decode(trim($output), true);

    expect($status)->toBe(0)
        ->and($rows)->toHaveCount(2)
        ->and($rows[0]['name'])->toBe('Ada');
});

it('runs a report as csv', function (): void {
    [$status, $output] = console('reports:run', [
        'report' => 'revenue-by-customer',
        '--param' => ['from=2026-01-01', 'to=2026-12-31'],
        '--format' => 'csv',
    ]);

    expect($status)->toBe(0)
        ->and($output)->toContain('Customer,Revenue')
        ->toContain('Ada,"49.99 USD"')
        ->toContain('Total,"55.49 USD"');
});

it('runs a report as html', function (): void {
    [$status, $output] = console('reports:run', [
        'report' => 'revenue-by-customer',
        '--param' => ['from=2026-01-01', 'to=2026-12-31'],
        '--format' => 'html',
    ]);

    expect($status)->toBe(0)
        ->and($output)->toContain('<title>Revenue by Customer</title>')
        ->toContain('<td>Total</td><td class="numeric">55.49 USD</td>');
});

it('stores a report instead of printing it', function (): void {
    Storage::fake('reports');

    [$status, $output] = console('reports:run', [
        'report' => 'all-orders',
        '--format' => 'csv',
        '--store' => true,
        '--disk' => 'reports',
    ]);

    expect($status)->toBe(0)
        ->and($output)->toContain('Stored as [reports/all-orders.csv]')
        ->and(Storage::disk('reports')->exists('reports/all-orders.csv'))->toBeTrue();
});

it('stores a report in a given directory', function (): void {
    Storage::fake('reports');

    [$status] = console('reports:run', [
        'report' => 'all-orders',
        '--store' => true,
        '--disk' => 'reports',
        '--path' => 'exports',
    ]);

    expect($status)->toBe(0)
        ->and(Storage::disk('reports')->exists('exports/all-orders.json'))->toBeTrue();
});

it('reports the validation errors of bad parameters', function (): void {
    [$status, $output] = console('reports:run', [
        'report' => 'revenue-by-customer',
        '--param' => ['from=nonsense'],
    ]);

    expect($status)->toBe(1)
        ->and($output)->toContain('from');
});

it('fails on a report it does not know', function (): void {
    [$status, $output] = console('reports:run', ['report' => 'nope']);

    expect($status)->toBe(1)
        ->and($output)->toContain('No report registered under the key [nope]');
});

it('fails on a format it cannot render', function (): void {
    [$status, $output] = console('reports:run', ['report' => 'all-orders', '--format' => 'pdf']);

    expect($status)->toBe(1)
        ->and($output)->toContain('No renderer is registered for [pdf]');
});

it('generates a report class', function (): void {
    $path = app_path('Reports/RevenueByRegion.php');

    try {
        [$status] = console('make:report', ['name' => 'RevenueByRegion']);

        expect($status)->toBe(0)
            ->and(file_exists($path))->toBeTrue();

        expect((string) file_get_contents($path))
            ->toContain('namespace App\Reports;')
            ->toContain('class RevenueByRegion extends Report')
            ->toContain("protected string \$key = 'revenue-by-region';")
            ->toContain('public function query(Datasets $datasets): DatasetQuery');
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});
