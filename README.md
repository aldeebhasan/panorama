# Panorama — one wide view across every source

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aldeebhasan/panorama.svg?style=flat-square)](https://packagist.org/packages/aldeebhasan/panorama)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/aldeebhasan/panorama/run-tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/aldeebhasan/panorama/actions?query=workflow%3Arun-tests+branch%3Amaster)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/aldeebhasan/panorama/fix-php-code-style-issues.yml?branch=master&label=code%20style&style=flat-square)](https://github.com/aldeebhasan/panorama/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amaster)
[![Total Downloads](https://img.shields.io/packagist/dt/aldeebhasan/panorama.svg?style=flat-square)](https://packagist.org/packages/aldeebhasan/panorama)

Reporting data is spread across application databases, external APIs and files, and every report re-implements fetching,
cleaning and joining it.

Panorama lets you define each source **once**, as a dataset that fetches its own rows and declares the exact shape it
guarantees, and then build reports that use it alone or merge it with any other dataset.

The mental model in one line: **datasets provide the rows, and a report is a query over them.**

```php
Datasets::from('orders')              // rows from a database table
    ->join('crm_customers')           // rows from an HTTP API; the join key is inferred
    ->groupBy('crm_customers.name', ['revenue' => Aggregate::sum('orders.amount')])
    ->get();
```

Panorama does not reach your sources for you. A dataset fetches with whatever the application already uses: a query
builder cursor, the HTTP client, an Eloquent model. The merge runs in PHP, so there is no database the package needs
to write to.

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13

## Installation

```bash
composer require aldeebhasan/panorama
php artisan vendor:publish --tag="panorama-config"
```

## The three concepts

| Concept | Responsibility | Example |
| --- | --- | --- |
| Dataset | Fetches rows from one source, and declares their schema. The reusable unit. | `orders`, `crm_customers` |
| Schema | The contract of a dataset: fields, types, key, field mapping, relations. | `customer_id` is a string referencing `crm_customers.id` |
| Report | Queries one dataset or merges several, then renders and delivers the result. | revenue by customer, as CSV |

## Datasets

```bash
php artisan make:dataset Orders
```

A dataset fetches its own rows and says what shape they are guaranteed to have:

```php
use Aldeebhasan\Panorama\Dataset\Dataset;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Schema\Schema;
use Illuminate\Support\Facades\DB;

class Orders extends Dataset
{
    public string $name = 'orders';

    // Filters this dataset accepts, with their validation rules.
    public function parameters(): array
    {
        return ['from' => ['date'], 'to' => ['date']];
    }

    public function fetch(Params $params): iterable
    {
        // Pushdown: the filters narrow the fetch, not the result.
        return DB::table('orders')
            ->whereBetween('created_at', [$params->from, $params->to])
            ->cursor();
    }

    public function schema(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->int('id')
            ->string('customer_id')->references('crm_customers.id')
            ->decimal('amount', 2)->currency('USD')
            ->date('created_at');
    }
}
```

And one reading an API, whose field names differ:

```php
class CrmCustomers extends Dataset
{
    public string $name = 'crm_customers';

    public function fetch(Params $params): iterable
    {
        for ($page = 1; ; $page++) {
            $rows = Http::withToken(config('services.crm.token'))
                ->get(config('services.crm.url') . '/customers', ['updated_since' => $params->from, 'page' => $page])
                ->throw()
                ->json('data');

            if ($rows === []) {
                return;
            }

            yield from $rows;
        }
    }

    public function schema(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->string('id', from: 'ext_id')
            ->string('name', from: 'custName')
            ->string('country')->nullable()
            ->datetime('created_at', from: 'createdAt')->timezone('UTC');
    }
}
```

Register them by listing or by discovery:

```php
'datasets' => [
    'register' => [App\Reports\Datasets\Orders::class],
    'discover' => [app_path('Reports/Datasets') => 'App\Reports\Datasets'],
],
```

Datasets are looked up by **name**, so reports never depend on class names. Return a generator or a cursor from
`fetch()` rather than an array, and a dataset of any size costs one row of memory. Any failure while fetching is
rethrown as `SourceUnavailable`, naming the dataset.

### Schema

Field types: `string`, `int`, `decimal(scale)`, `bool`, `date`, `datetime`, `json`, `enum(values)`.

Modifiers: `nullable()`, `from('rawName')`, `default()`, `timezone($output, $source)`, `currency()`,
`references('dataset.field')`, and `primaryKey()` on the schema.

Every row is processed in one fixed order: **map** raw field names → apply **defaults** → **cast** to the declared type →
**normalize** → **validate** → apply the **violation policy**. Output rows carry the declared fields and nothing else.

Casts reject rather than guess. `'1.5'` on an `int` field fails instead of silently dropping the fraction, and a
non-empty string on a `bool` field fails instead of following PHP's truthiness.

When a row breaks its contract, the dataset's policy decides:

```php
Schema::make()->skipOnViolation()   // drop the row, and count it
Schema::make()->nullifyOnViolation()// null the value, and count it
Schema::make()->failOnViolation()   // the default: stop, naming dataset, field, row and reason
```

```php
$dataset->log()->skipped();      // rows dropped
$dataset->log()->nullified();    // values nulled
$dataset->log()->violations();   // a sample of the reasons
```

## Querying and merging

A report merges datasets with a fluent query that runs in PHP:

```php
use Aldeebhasan\Panorama\Facades\Datasets;
use Aldeebhasan\Panorama\Query\Aggregate;

// One dataset, standalone.
Datasets::from('orders')->params(['from' => '2026-01-01'])->get();

// Join on a shared column: keys inferred from the references() the schemas declare...
Datasets::from('orders')
    ->join('crm_customers')
    ->groupBy('crm_customers.name', [
        'revenue' => Aggregate::sum('orders.amount'),
        'orders' => Aggregate::count(),
    ])
    ->orderBy('revenue', 'desc')
    ->get();

// ...or named, in either order.
Datasets::from('orders')->leftJoin('crm_customers', 'orders.customer_id', 'crm_customers.id');

// Append same-shaped datasets, tagging where each row came from.
Datasets::union('shop_a_sales', 'shop_b_sales')->tagSource('source')->get();
```

Columns are written `field`, or `dataset.field` when more than one dataset declares the field.

| | |
| --- | --- |
| Joining | `join`, `leftJoin` |
| Filtering, before grouping | `where`, `whereIn`, `whereNotIn`, `whereBetween`, `whereNull`, `whereNotNull`, and `filter(fn (array $row) => ...)` for anything else, with the row keyed by `dataset.field` |
| Grouping | `groupBy($columns, $aggregates)`, `aggregate($aggregates)` for totals over every row, `having` on the output columns |
| Aggregates | `Aggregate::sum`, `avg`, `min`, `max`, `count()`, `count('field')`, `countDistinct` |
| Shaping | `select('orders.id', 'crm_customers.name as customer')`, `orderBy`, `limit`, `offset` |
| Running | `get()`, `first()`, `result()` for a streamed result, `loaded()` for row counts per dataset |

**Checked before anything is fetched:**

- Every column must exist, and an unqualified one must belong to exactly one dataset in the query.
- Join keys must have comparable declared types. Joining a string key against an int key fails with a clear error instead
  of returning an empty result that looks like "no data".
- Union members must share field names and types.
- `sum` and `avg` need a numeric field.

**How it runs.** The first dataset streams. Each joined dataset is read once and held in memory, indexed by its join key.
Grouping and sorting hold their rows in memory too, as any engine has to. A query that neither groups nor sorts holds
nothing but its joined datasets. Each dataset receives only the parameters it declares.

**Semantics follow SQL.** A null on either side of a comparison matches nothing, `not in` a list holding a null matches
nothing, and aggregates skip nulls. `like` is case sensitive and `ilike` is not. Nulls sort first ascending and last
descending, as in SQLite and MySQL. Decimals are summed exactly at their declared scale, so `19.99 + 30.00` is `49.99`.
With no `select`, every field of every dataset is returned, and a field name two datasets share comes out as
`dataset.field` for both.

## Reports

```bash
php artisan make:report RevenueByCustomer
```

```php
use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Query\Aggregate;
use Aldeebhasan\Panorama\Query\DatasetQuery;
use Aldeebhasan\Panorama\Report\Column;
use Aldeebhasan\Panorama\Report\Report;

class RevenueByCustomer extends Report
{
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
            ->orderBy('revenue', 'desc');
    }

    public function columns(): array
    {
        return [
            Column::make('name')->label('Customer'),
            Column::make('revenue')->money('USD')->total(),
        ];
    }

    public function authorize(mixed $user = null): bool
    {
        return $user?->can('view-finance-reports') ?? false;
    }
}
```

Register it in `config/panorama.php` under `reports`, then run it:

```php
use Aldeebhasan\Panorama\Facades\Panorama;

$run = Panorama::run('revenue-by-customer', ['from' => '2026-01-01', 'to' => '2026-09-30']);

$run->get();                        // rows as an array
$run->rows();                       // rows as a generator
$run->toCsv()->download();          // a streamed response
$run->toCsv()->store('s3');         // writes it, returns the path
$run->toHtml()->download();         // a standalone page with the table
$run->toJson()->contents();         // the document as a string
```

Parameters are validated against the rules the report declares. Pass a user as the third argument to `run()` and the
report's own `authorize()` is enforced:

```php
Panorama::run('revenue-by-customer', $filters, $request->user());
```

`key()` defaults to the kebab-cased class basename and `name()` to a readable form, so both are optional.

### Columns

`label()`, `money($currency, $decimals)`, `number($decimals)`, `percent($decimals)`, `date($format)`,
`datetime($format)`, `whenEmpty($placeholder)`, `total()`, and `format(fn ($value, $row) => ...)` for anything else.

A column carries two readings of the same cell, which is why one report renders correctly in every format:

- **CSV** uses the display reading — a spreadsheet is opened by a person, so money, dates and percentages are formatted,
  and `total()` columns get a footer.
- **HTML** uses the display reading too, as a standalone page with the table, a totals footer, and numeric columns
  right aligned. It carries its own stylesheet and loads nothing, so it opens the same from a download, a disk or a
  mail attachment. Every value is escaped.
- **JSON** uses the raw reading — another system wants `1234.56`, not `"1,234.56 USD"`.

To embed the bare table in a page of your own, or to style it yourself, register a configured renderer under `html`:

```php
app(RendererRegistry::class)->register(new HtmlRenderer(document: false));
app(RendererRegistry::class)->register(new HtmlRenderer(stylesheet: file_get_contents(resource_path('css/report.css'))));
```

## Console

```bash
php artisan datasets:list                                   # datasets, keys and relations
php artisan datasets:describe orders --sample=5              # schema, parameters and a sample of rows
php artisan reports:list                                     # reports, their parameters and datasets
php artisan reports:run revenue-by-customer --param=from=2026-01-01 --param=to=2026-09-30 --format=html
php artisan reports:run revenue-by-customer --store --disk=s3
php artisan make:dataset CrmCustomers
php artisan make:report RevenueByCustomer
```

## Testing

Replace any source with fixture rows. The real schema still applies, so a fixture that does not honour the contract fails
exactly as the real source would:

```php
Datasets::fake('crm_customers', [
    ['ext_id' => 'c-1', 'custName' => 'Ada', 'country' => 'DE'],
]);
```

```bash
composer test
```

The suite needs nothing installed: the one dataset that reads a real table does so from SQLite in memory.

## Extending

Every stage is an interface, and the built-ins are ordinary implementations:

| To add | Implement | Register |
| --- | --- | --- |
| A format (XLSX, PDF) | `Render\RendererContract` | `RendererRegistry::register()` |
| A destination (mail, Slack) | `Deliver\DestinationContract` | pass it to `RenderedReport::to()` |
| A source | extend `Dataset\Dataset` | the `datasets` config |
| A field type's casting | `Schema\Casts\Cast` | pass it to `Schema\Processor` |

## Roadmap

Not yet shipped: XLSX and PDF renderers, mail and notification delivery, queued runs, scheduling, run history,
snapshots and incremental sync, result caching.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Hasan Deeb](https://github.com/aldeebhasan)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
