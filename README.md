# Panorama — one wide view across every source

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aldeebhasan/panorama.svg?style=flat-square)](https://packagist.org/packages/aldeebhasan/panorama)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/aldeebhasan/panorama/run-tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/aldeebhasan/panorama/actions?query=workflow%3Arun-tests+branch%3Amaster)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/aldeebhasan/panorama/fix-php-code-style-issues.yml?branch=master&label=code%20style&style=flat-square)](https://github.com/aldeebhasan/panorama/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amaster)
[![Total Downloads](https://img.shields.io/packagist/dt/aldeebhasan/panorama.svg?style=flat-square)](https://packagist.org/packages/aldeebhasan/panorama)

Panorama lets you define a report once — its key, its allowed filters and how its payload is built — and then resolve, cache
and generate it from anywhere in your application: controllers, jobs, or the console.

A report's `build()` is plain PHP, so one report can aggregate an Eloquent query, a raw SQL join on a second connection and
an HTTP API into a single payload. Panorama owns the rest: the registry, the filter whitelist, the per-filter cache key and
the console entry points.

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13

## Installation

You can install the package via composer:

```bash
composer require aldeebhasan/panorama
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="panorama-config"
```

## Usage

### Defining a report

Extend the base `Report` class and implement `build()`:

```php
use Aldeebhasan\Panorama\Reports\Report;

class SalesReport extends Report
{
    protected string $key = 'sales';

    protected string $name = 'Monthly Sales';

    // Any filter outside this list is discarded, leave it empty to allow all.
    protected array $allowedFilters = ['from', 'to', 'country'];

    public function build(): array
    {
        return Order::query()
            ->when($this->filter('country'), fn ($q, $country) => $q->where('country', $country))
            ->whereBetween('created_at', [$this->filter('from'), $this->filter('to')])
            ->selectRaw('count(*) as orders, sum(total) as revenue')
            ->first()
            ->toArray();
    }
}
```

`key()` defaults to the kebab-cased class basename and `name()` to its headlined form, so both properties are optional.

### Registering a report

Add it to the `reports` array of `config/panorama.php`:

```php
'reports' => [
    App\Reports\SalesReport::class,
],
```

Or register one at runtime — a class name or a ready made instance:

```php
Panorama::register(App\Reports\SalesReport::class);
```

### Generating a report

```php
use Aldeebhasan\Panorama\Facades\Panorama;

Panorama::generate('sales', ['from' => '2026-01-01', 'to' => '2026-01-31']);

Panorama::all();            // ['sales' => 'Monthly Sales']
Panorama::has('sales');     // true
Panorama::report('sales');  // a fresh report instance
```

### Caching

Caching is off by default. Set a ttl in seconds to turn it on — payloads are cached per report **and** per filter set:

```php
// config/panorama.php
'cache' => [
    'ttl' => 3600,
    'store' => 'redis',
    'prefix' => 'panorama',
],
```

Override the ttl per report by implementing `cacheTtl()`, and drop a cached payload with:

```php
Panorama::forget('sales', ['from' => '2026-01-01', 'to' => '2026-01-31']);
```

### Console

```bash
php artisan panorama:list
php artisan panorama:generate sales --filter=country=DE --fresh
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Hasan Deeb](https://github.com/aldeebhasan)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
