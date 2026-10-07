# Panorama — one wide view across every source

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aldeebhasan/panorama.svg?style=flat-square)](https://packagist.org/packages/aldeebhasan/panorama)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/aldeebhasan/panorama/run-tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/aldeebhasan/panorama/actions?query=workflow%3Arun-tests+branch%3Amaster)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/aldeebhasan/panorama/fix-php-code-style-issues.yml?branch=master&label=code%20style&style=flat-square)](https://github.com/aldeebhasan/panorama/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amaster)
[![Total Downloads](https://img.shields.io/packagist/dt/aldeebhasan/panorama.svg?style=flat-square)](https://packagist.org/packages/aldeebhasan/panorama)

Reporting data is spread across application databases, external APIs and files, and every report re-implements fetching,
cleaning and joining it.

Panorama lets you define each source **once** — where the data lives, plus the exact shape it guarantees — and then use it
alone or merge it with any other source into a single report.

The mental model in one line: **sources are tables, and a report is a query over them.**

```php
Datasets::from('orders')              // a MySQL table
    ->join('crm_customers')           // an HTTP API; the join key is inferred
    ->select('crm_customers.name', 'sum(orders.amount) as revenue')
    ->groupBy('crm_customers.name')
    ->get();
```

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13

## Installation

```bash
composer require aldeebhasan/panorama
php artisan vendor:publish --tag="panorama-config"
```

## The five concepts

| Concept | Responsibility | Example |
| --- | --- | --- |
| Connection | How to reach a system: credentials, base URL, auth. Defined once, shared by many datasets. | `mysql`, `crm_api` |
| Dataset | What to pull from a connection, plus its schema. The reusable unit. | `orders`, `crm_customers` |
| Schema | The contract of a dataset: fields, types, key, field mapping, relations. | `customer_id` is a string referencing `crm_customers.id` |
| Workspace | The engine where datasets become tables and queries run. | the application database |
| Report | Uses one dataset or merges several, then renders and delivers the result. | revenue by customer, as CSV |

## Connections

Declare each system once, in `config/panorama.php`:

```php
'connections' => [
    'mysql' => ['driver' => 'database', 'connection' => 'mysql'],

    'crm_api' => [
        'driver' => 'http',
        'base_url' => env('CRM_URL'),
        'auth' => ['bearer' => env('CRM_TOKEN')],
    ],

    // For tests and fixtures.
    'memory' => ['driver' => 'array', 'tables' => ['orders' => [/* rows */]]],
],
```

Connections are built lazily, so declaring one never opens a socket until a dataset fetches from it.

## Datasets

```bash
php artisan make:dataset Orders
```

A dataset says where its rows come from and what shape they are guaranteed to have:

```php
use Aldeebhasan\Panorama\Dataset\Dataset;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Schema\Schema;

class Orders extends Dataset
{
    public string $name = 'orders';
    public string $connection = 'mysql';

    // Filters this dataset accepts, with their validation rules.
    public function parameters(): array
    {
        return ['from' => ['date'], 'to' => ['date']];
    }

    public function fetch(Params $params): iterable
    {
        // Pushdown: the filters narrow the fetch, not the result.
        return $this->query()->table('orders')
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
    public string $connection = 'crm_api';

    public function fetch(Params $params): iterable
    {
        return $this->http()->paginate('/customers', ['updated_since' => $params->from]);
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

Datasets are looked up by **name**, so reports never depend on class names. Rows are generators throughout, so a dataset
of any size costs one row of memory.

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

## Merging

Merging is a query over workspace tables. Panorama does not invent join semantics; it describes the merge and the engine
executes it.

```php
use Aldeebhasan\Panorama\Facades\Datasets;

// One dataset, standalone.
Datasets::from('orders')->params(['from' => '2026-01-01'])->get();

// Enrich: keys inferred from the references() the schemas declare.
Datasets::from('orders')
    ->join('crm_customers')
    ->select('crm_customers.name', 'sum(orders.amount) as revenue')
    ->groupBy('crm_customers.name')
    ->orderBy('revenue', 'desc')
    ->get();

// Append same-shaped datasets, tagging where each row came from.
Datasets::union('shop_a_sales', 'shop_b_sales')->tagSource('source')->get();

// Hand written, for anything the builder does not cover.
Datasets::sql('select c.name, count(*) as orders
               from orders o join crm_customers c on c.id = o.customer_id
               group by c.name')->get();
```

Also available: `leftJoin`, `where`, `whereIn`, `whereNotIn`, `whereBetween`, `whereNull`, `whereNotNull`, `whereRaw`,
`havingRaw`, `limit`, `offset`, and `result()` for a streamed result instead of an array.

**Checked before anything is fetched:**

- Join keys must have comparable declared types. Joining a string key against an int key fails with a clear error instead
  of returning an empty result that looks like "no data".
- Union members must share field names and types.
- Only the datasets the query names are fetched and loaded.
- Parameters are passed down to every dataset that declares them.

## Reports

```bash
php artisan make:report RevenueByCustomer
```

```php
use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Query\Merge;
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

    public function query(Datasets $datasets): Merge
    {
        return $datasets->from('orders')
            ->join('crm_customers')
            ->select('crm_customers.name', 'sum(orders.amount) as revenue')
            ->groupBy('crm_customers.name');
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

A column carries two readings of the same cell, which is why one report renders correctly in both formats:

- **CSV** uses the display reading — a spreadsheet is opened by a person, so money, dates and percentages are formatted,
  and `total()` columns get a footer.
- **JSON** uses the raw reading — another system wants `1234.56`, not `"1,234.56 USD"`.

## Workspaces

The workspace is the engine datasets are loaded into and merged in. Datasets and reports are identical across engines, so
it is a deployment choice:

```php
'workspace' => env('PANORAMA_WORKSPACE', 'database'),
```

The MVP ships one engine. `DatabaseWorkspace` loads each dataset into a temporary table in a database the application
already has and merges them with SQL.

Temporary tables are invisible to other sessions and vanish with the connection, so a crashed run leaves nothing behind.
They need `CREATE TEMPORARY TABLE` and a connection held open for the whole run, which some managed databases and
pgbouncer in transaction-pooling mode do not give. Turn them off and real prefixed tables are created and dropped
instead:

```php
'workspaces' => ['database' => ['temporary' => false]],
```

Tables are dropped when the rows have been read, when the result is abandoned half way, and when a dataset fails to load.

Note: hand written SQL is wrapped in common table expressions, so `Datasets::sql()` needs MySQL 8+, PostgreSQL, or
SQLite 3.8.3+. The fluent builder has no such requirement.

## Console

```bash
php artisan datasets:list                                   # datasets, connections and relations
php artisan datasets:describe orders --sample=5              # schema, parameters and a sample of rows
php artisan reports:list                                     # reports and the parameters they accept
php artisan reports:run revenue-by-customer --param=from=2026-01-01 --param=to=2026-09-30 --format=csv
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

## Extending

Every stage is an interface, and the built-ins are ordinary implementations:

| To add | Implement | Register |
| --- | --- | --- |
| A format (XLSX, PDF, HTML) | `Render\RendererContract` | `RendererRegistry::register()` |
| A destination (mail, Slack) | `Deliver\DestinationContract` | pass it to `RenderedReport::to()` |
| An engine (DuckDB, a warehouse) | `Workspace\WorkspaceContract` | `WorkspaceFactory` |
| A connection driver | `Connection\ConnectionContract` | `ConnectionRegistry::register()` |
| A field type's casting | `Schema\Casts\Cast` | pass it to `Schema\Processor` |

A new workspace engine must pass the shared contract suite in `tests/Workspace/WorkspaceContractTest.php`, which
describes behaviour only and mentions no SQL.

## Roadmap

See [docs/roadmap.md](docs/roadmap.md) for what is in the MVP, what was deliberately left out, and why.

Not yet shipped: `PhpWorkspace`, DuckDB, XLSX / PDF / HTML renderers, mail and notification delivery, queued runs,
scheduling, run history, snapshots and incremental sync, result caching.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Hasan Deeb](https://github.com/aldeebhasan)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
