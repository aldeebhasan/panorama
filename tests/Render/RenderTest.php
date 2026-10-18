<?php

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Panorama;
use Aldeebhasan\Panorama\Query\Result;
use Aldeebhasan\Panorama\Render\CsvRenderer;
use Aldeebhasan\Panorama\Render\HtmlRenderer;
use Aldeebhasan\Panorama\Render\JsonRenderer;
use Aldeebhasan\Panorama\Render\RendererRegistry;
use Aldeebhasan\Panorama\Render\XlsxRenderer;
use Aldeebhasan\Panorama\Report\Column;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    app(Datasets::class)->fake('crm_customers', [
        ['ext_id' => 'c-1', 'custName' => 'Ada', 'country' => 'DE'],
        ['ext_id' => 'c-2', 'custName' => 'Linus', 'country' => null],
    ]);
});

/**
 * The sheet XML and the workbook XML inside a rendered XLSX.
 *
 * @param list<array<string,mixed>> $rows
 * @param list<Column> $columns
 * @return array{0:string,1:string}
 */
function xlsx(array $rows, array $columns = [], string $title = ''): array
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
    file_put_contents($path, rendered(new XlsxRenderer, $rows, $columns, $title));

    $zip = new ZipArchive;
    $zip->open($path);
    $parts = [(string) $zip->getFromName('xl/worksheets/sheet1.xml'), (string) $zip->getFromName('xl/workbook.xml')];
    $zip->close();
    unlink($path);

    return $parts;
}

/**
 * @param list<array<string,mixed>> $rows
 * @param list<Column> $columns
 */
function rendered(object $renderer, array $rows, array $columns = [], string $title = ''): string
{
    $out = '';
    foreach ($renderer->render(Result::of($rows), $columns, $title) as $chunk) {
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
| html
|--------------------------------------------------------------------------
*/

it('writes a standalone page titled after the report', function (): void {
    $html = rendered(new HtmlRenderer, [['name' => 'Ada']], [Column::make('name')->label('Customer')], 'Revenue');

    expect($html)->toStartWith('<!doctype html>')
        ->toContain('<meta charset="utf-8">')
        ->toContain('<title>Revenue</title>')
        ->toContain('<h1>Revenue</h1>')
        ->toContain('<th scope="col">Customer</th>')
        ->toContain('<tr><td>Ada</td></tr>')
        ->toEndWith("</body>\n</html>\n");
});

it('writes display values and right aligns the numeric columns', function (): void {
    $html = rendered(new HtmlRenderer, [['name' => 'Ada', 'revenue' => 1234.5]], [
        Column::make('name'),
        Column::make('revenue')->money('USD'),
    ]);

    expect($html)->toContain('<th class="numeric" scope="col">Revenue</th>')
        ->toContain('<td>Ada</td><td class="numeric">1,234.50 USD</td>');
});

it('escapes every value', function (): void {
    $html = rendered(
        new HtmlRenderer,
        [['note' => '<script>alert("x")</script> & \'more\'']],
        [Column::make('note')->label('<b>Note</b>')],
        '<i>Title</i>',
    );

    expect($html)->not->toContain('<script>')
        ->not->toContain('<b>')
        ->not->toContain('<i>')
        ->toContain('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &apos;more&apos;')
        ->toContain('<title>&lt;i&gt;Title&lt;/i&gt;</title>');
});

it('writes a totals footer', function (): void {
    $html = rendered(new HtmlRenderer, [['name' => 'Ada', 'n' => 2], ['name' => 'Linus', 'n' => 3]], [
        Column::make('name'),
        Column::make('n')->number()->total(),
    ]);

    expect($html)->toContain('<tfoot><tr><td>Total</td><td class="numeric">5</td></tr></tfoot>');
});

it('says so when there are no rows', function (): void {
    $html = rendered(new HtmlRenderer, [], [Column::make('a'), Column::make('b')]);

    expect($html)->toContain('<tr><td class="empty" colspan="2">No rows</td></tr>')
        ->not->toContain('<tfoot>');
});

it('falls back to the result columns and a generic title', function (): void {
    $html = rendered(new HtmlRenderer, [['customer_id' => 'c-1']]);

    expect($html)->toContain('<title>Report</title>')
        ->not->toContain('<h1>')
        ->toContain('<th scope="col">Customer Id</th>');
});

it('writes a bare table to embed', function (): void {
    $html = rendered(new HtmlRenderer(document: false), [['a' => 1]], title: 'Ignored');

    expect($html)->toStartWith('<div class="panorama-table-wrap"><table class="panorama-report">')
        ->toEndWith("</table></div>\n")
        ->not->toContain('<html')
        ->not->toContain('Ignored');
});

it('uses a given stylesheet instead of its own', function (): void {
    $html = rendered(new HtmlRenderer(stylesheet: 'td { color: red; }'), [['a' => 1]]);

    expect($html)->toContain('<style>td { color: red; }</style>')
        ->not->toContain('tabular-nums');
});

/*
|--------------------------------------------------------------------------
| the registry
|--------------------------------------------------------------------------
*/

it('serves the built in formats', function (): void {
    $registry = app(RendererRegistry::class);

    expect($registry->formats())->toEqualCanonicalizing(['csv', 'html', 'json', 'xlsx'])
        ->and($registry->has('CSV'))->toBeTrue()
        ->and($registry->get('json'))->toBeInstanceOf(JsonRenderer::class);
});

it('lets a renderer be replaced under its own name', function (): void {
    $registry = app(RendererRegistry::class);
    $replacement = new CsvRenderer(separator: '|');

    $registry->register($replacement);

    expect($registry->get('csv'))->toBe($replacement)
        ->and($registry->formats())->toHaveCount(4);
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

it('renders the acceptance report as html', function (): void {
    $html = app(Panorama::class)
        ->run('revenue-by-customer', ['from' => '2026-01-01', 'to' => '2026-12-31'])
        ->toHtml();

    expect($html->filename())->toBe('revenue-by-customer.html')
        ->and($html->contentType())->toBe('text/html; charset=UTF-8')
        ->and($html->contents())->toContain('<title>Revenue by Customer</title>')
        ->toContain('<td>Ada</td><td class="numeric">49.99 USD</td>')
        ->toContain('<td>Linus</td><td class="numeric">5.50 USD</td>')
        ->toContain('<td>Total</td><td class="numeric">55.49 USD</td>');
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

/*
|--------------------------------------------------------------------------
| xlsx
|--------------------------------------------------------------------------
*/

it('writes a workbook whose sheet is named after the report', function (): void {
    [$sheet, $workbook] = xlsx([['name' => 'Ada']], [Column::make('name')->label('Customer')], 'Revenue: Q3 / EU');

    expect($workbook)->toContain('<sheet name="Revenue Q3  EU" sheetId="1" r:id="rId1"/>')
        ->and($sheet)->toContain('<row r="1"><c t="inlineStr"><is><t xml:space="preserve">Customer</t></is></c></row>')
        ->and($sheet)->toContain('<row r="2"><c t="inlineStr"><is><t xml:space="preserve">Ada</t></is></c></row>');
});

it('writes numeric columns as numbers and percentages in the percent format', function (): void {
    [$sheet] = xlsx([['name' => 'Ada', 'revenue' => '1234.5', 'share' => 0.25]], [
        Column::make('name'),
        Column::make('revenue')->money('EUR'),
        Column::make('share')->percent(),
    ]);

    expect($sheet)->toContain('<c><v>1234.5</v></c><c s="1"><v>0.25</v></c>');
});

it('keeps formulas and markup in a dataset as plain text', function (): void {
    [$sheet] = xlsx([['note' => "=HYPERLINK(\"x\")<b>\x07"]]);

    expect($sheet)->toContain('<t xml:space="preserve">=HYPERLINK(&quot;x&quot;)&lt;b&gt;</t>')
        ->and($sheet)->not->toContain('<f>');
});

it('adds the totals row and falls back to a sheet named Report', function (): void {
    [$sheet, $workbook] = xlsx([['name' => 'Ada', 'n' => 2], ['name' => 'Linus', 'n' => 3]], [
        Column::make('name'),
        Column::make('n')->total(),
    ]);

    expect($sheet)->toContain('<row r="4"><c t="inlineStr"><is><t xml:space="preserve">Total</t></is></c><c t="inlineStr"><is><t xml:space="preserve">5</t></is></c></row>')
        ->and($workbook)->toContain('<sheet name="Report"');
});

it('stores a report as xlsx', function (): void {
    Storage::fake('reports');

    $path = app(Panorama::class)->run('all-orders')->toXlsx()->store('reports');

    expect($path)->toBe('reports/all-orders.xlsx')
        ->and(Storage::disk('reports')->get($path))->toStartWith("PK\x03\x04");
});
