<?php

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Panorama;
use Aldeebhasan\Panorama\Query\Result;
use Aldeebhasan\Panorama\Render\CsvRenderer;
use Aldeebhasan\Panorama\Render\JsonRenderer;
use Aldeebhasan\Panorama\Render\RendererRegistry;
use Aldeebhasan\Panorama\Report\Column;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    app(Datasets::class)->fake('crm_customers', [
        ['ext_id' => 'c-1', 'custName' => 'Ada', 'country' => 'DE'],
        ['ext_id' => 'c-2', 'custName' => 'Linus', 'country' => null],
    ]);
});

/**
 * @param list<array<string,mixed>> $rows
 * @param list<Column> $columns
 */
function rendered(object $renderer, array $rows, array $columns = []): string
{
    $out = '';
    foreach ($renderer->render(Result::of($rows), $columns) as $chunk) {
        $out .= $chunk;
    }

    return $out;
}

/*
|--------------------------------------------------------------------------
| csv
|--------------------------------------------------------------------------
*/

it('writes a header from the column labels', function (): void {
    $csv = rendered(new CsvRenderer, [['name' => 'Ada', 'revenue' => 49.99]], [
        Column::make('name')->label('Customer'),
        Column::make('revenue')->money('USD'),
    ]);

    expect($csv)->toBe("Customer,Revenue\nAda,\"49.99 USD\"\n");
});

it('falls back to the result columns when none are declared', function (): void {
    $csv = rendered(new CsvRenderer, [['customer_id' => 'c-1', 'amount' => 10]]);

    // fputcsv quotes any field containing a space, which is why the derived
    // heading is quoted and the values are not.
    expect($csv)->toBe("\"Customer Id\",Amount\nc-1,10\n");
});

it('escapes the cells that need it', function (): void {
    $csv = rendered(new CsvRenderer, [['note' => 'a "quoted", comma' . "\n" . 'and a newline']]);

    expect($csv)->toContain('"a ""quoted"", comma')
        ->and(str_getcsv(explode("\n", $csv)[1] . "\n" . explode("\n", $csv)[2])[0] ?? '')
        ->toContain('quoted');
});

it('sums the columns that asked to be totalled', function (): void {
    $csv = rendered(new CsvRenderer, [
        ['name' => 'Ada', 'revenue' => '19.99'],
        ['name' => 'Linus', 'revenue' => '30.01'],
    ], [
        Column::make('name')->label('Customer'),
        Column::make('revenue')->money('USD')->total(),
    ]);

    expect($csv)->toBe("Customer,Revenue\nAda,\"19.99 USD\"\nLinus,\"30.01 USD\"\nTotal,\"50.00 USD\"\n");
});

it('writes no footer when nothing is totalled', function (): void {
    $csv = rendered(new CsvRenderer, [['n' => 1]], [Column::make('n')]);

    expect($csv)->toBe("N\n1\n");
});

it('writes a bom when asked, for excel', function (): void {
    expect(rendered(new CsvRenderer(bom: true), [['n' => 1]]))->toStartWith("\xEF\xBB\xBF");
});

it('writes a header even for an empty result', function (): void {
    $csv = rendered(new CsvRenderer, [], [Column::make('name')->label('Customer')]);

    expect($csv)->toBe("Customer\n");
});

it('honours a different separator', function (): void {
    expect(rendered(new CsvRenderer(separator: ';'), [['a' => 1, 'b' => 2]]))->toBe("A;B\n1;2\n");
});

/*
|--------------------------------------------------------------------------
| json
|--------------------------------------------------------------------------
*/

it('writes a json array of objects', function (): void {
    $json = rendered(new JsonRenderer, [['name' => 'Ada', 'revenue' => 49.99]]);

    expect($json)->toBe('[{"name":"Ada","revenue":49.99}]')
        ->and(json_decode($json, true))->toBe([['name' => 'Ada', 'revenue' => 49.99]]);
});

it('writes raw values, not formatted ones', function (): void {
    // The display reading would be "49.99 USD"; a consuming system wants the
    // number.
    $json = rendered(new JsonRenderer, [['revenue' => 49.99]], [Column::make('revenue')->money('USD')]);

    expect(json_decode($json, true))->toBe([['revenue' => 49.99]]);
});

it('keeps only the declared columns', function (): void {
    $json = rendered(new JsonRenderer, [['a' => 1, 'b' => 2]], [Column::make('b')]);

    expect(json_decode($json, true))->toBe([['b' => 2]]);
});

it('writes an empty json array for an empty result', function (): void {
    expect(rendered(new JsonRenderer, []))->toBe('[]');
});

it('separates rows with commas and nothing else', function (): void {
    expect(rendered(new JsonRenderer, [['n' => 1], ['n' => 2], ['n' => 3]]))
        ->toBe('[{"n":1},{"n":2},{"n":3}]');
});

/*
|--------------------------------------------------------------------------
| the registry
|--------------------------------------------------------------------------
*/

it('serves the built in formats', function (): void {
    $registry = app(RendererRegistry::class);

    expect($registry->formats())->toEqualCanonicalizing(['csv', 'json'])
        ->and($registry->has('CSV'))->toBeTrue()
        ->and($registry->get('json'))->toBeInstanceOf(JsonRenderer::class);
});

it('lets a renderer be replaced under its own name', function (): void {
    $registry = app(RendererRegistry::class);
    $replacement = new CsvRenderer(separator: '|');

    $registry->register($replacement);

    expect($registry->get('csv'))->toBe($replacement)
        ->and($registry->formats())->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| end to end, through a report
|--------------------------------------------------------------------------
*/

it('renders the acceptance report as csv', function (): void {
    $csv = app(Panorama::class)
        ->run('revenue-by-customer', ['from' => '2026-01-01', 'to' => '2026-12-31'])
        ->toCsv();

    expect($csv->filename())->toBe('revenue-by-customer.csv')
        ->and($csv->contentType())->toBe('text/csv')
        ->and($csv->contents())->toContain('Customer,Revenue')
        ->toContain('Ada,"49.99 USD"')
        ->toContain('Linus,"5.50 USD"')
        ->toContain('Total,"55.49 USD"');
});

it('renders the acceptance report as json', function (): void {
    $json = app(Panorama::class)
        ->run('revenue-by-customer', ['from' => '2026-01-01', 'to' => '2026-12-31'])
        ->toJson();

    $rows = json_decode($json->contents(), true);

    expect($json->filename())->toBe('revenue-by-customer.json')
        ->and($json->contentType())->toBe('application/json')
        ->and($rows)->toHaveCount(2)
        ->and($rows[0]['name'])->toBe('Ada');
});

it('lets the file be named', function (): void {
    $csv = app(Panorama::class)->run('all-orders')->named('orders-2026.csv')->toCsv();

    expect($csv->filename())->toBe('orders-2026.csv');
});

it('refuses to deliver the same rendering twice', function (): void {
    $csv = app(Panorama::class)->run('all-orders')->toCsv();
    $csv->contents();

    $csv->contents();
})->throws(RuntimeException::class, 'has already been delivered');

/*
|--------------------------------------------------------------------------
| delivery
|--------------------------------------------------------------------------
*/

it('streams a download with the right headers', function (): void {
    $response = app(Panorama::class)->run('all-orders')->toCsv()->download();

    ob_start();
    $response->sendContent();
    $body = (string) ob_get_clean();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('text/csv')
        ->and($response->headers->get('Content-Disposition'))
        ->toBe('attachment; filename="all-orders.csv"')
        ->and($body)->toContain('Id,"Customer Id",Amount,"Created At"');
});

it('stores the report on a disk and returns its path', function (): void {
    Storage::fake('reports');

    $path = app(Panorama::class)->run('all-orders')->toCsv()->store('reports');

    expect($path)->toBe('reports/all-orders.csv')
        ->and(Storage::disk('reports')->exists($path))->toBeTrue()
        ->and(Storage::disk('reports')->get($path))->toContain('Customer Id');
});

it('stores in a given directory', function (): void {
    Storage::fake('reports');

    $path = app(Panorama::class)->run('all-orders')->toCsv()->store('reports', 'exports/daily');

    expect($path)->toBe('exports/daily/all-orders.csv')
        ->and(Storage::disk('reports')->exists($path))->toBeTrue();
});

it('stores at the root when the directory is empty', function (): void {
    Storage::fake('reports');

    $path = app(Panorama::class)->run('all-orders')->toCsv()->store('reports', '');

    expect($path)->toBe('all-orders.csv');
});
