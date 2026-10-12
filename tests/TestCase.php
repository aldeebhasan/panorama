<?php

namespace Aldeebhasan\Panorama\Tests;

use Aldeebhasan\Panorama\PanoramaServiceProvider;
use Aldeebhasan\Panorama\Tests\Datasets\CrmCustomers;
use Aldeebhasan\Panorama\Tests\Datasets\DbOrders;
use Aldeebhasan\Panorama\Tests\Datasets\Orders;
use Aldeebhasan\Panorama\Tests\Datasets\ShopASales;
use Aldeebhasan\Panorama\Tests\Datasets\ShopBSales;
use Aldeebhasan\Panorama\Tests\Datasets\ShopCSales;
use Aldeebhasan\Panorama\Tests\Reports\AllOrders;
use Aldeebhasan\Panorama\Tests\Reports\FinanceOnly;
use Aldeebhasan\Panorama\Tests\Reports\RevenueByCustomer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;

class TestCase extends Orchestra
{
    /**
     * The fixture rows the `fixtures` connection serves.
     *
     * @var list<array<string,mixed>>
     */
    public const ORDERS = [
        ['id' => 1, 'customer_id' => 'c-1', 'amount' => '19.99', 'created_at' => '2026-01-15'],
        ['id' => 2, 'customer_id' => 'c-2', 'amount' => '5.50', 'created_at' => '2026-02-20'],
        ['id' => 3, 'customer_id' => 'c-1', 'amount' => '30.00', 'created_at' => '2026-03-05'],
    ];

    protected function getPackageProviders($app): array
    {
        return [
            PanoramaServiceProvider::class,
        ];
    }

    /**
     * A real database server keeps whatever a test created; SQLite in memory
     * is thrown away with the connection. Dropping up front rather than after
     * each test also clears what an interrupted run left behind.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (self::driver() !== 'sqlite') {
            Schema::dropAllTables();
        }
    }

    /**
     * One connection per test, closed with the test. A server has a finite
     * connection limit, and nothing else releases the handle before the whole
     * suite ends — 368 of them is well past PostgreSQL's default of 100.
     */
    protected function tearDown(): void
    {
        if (self::driver() !== 'sqlite') {
            DB::disconnect();
        }

        parent::tearDown();
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', self::connection());
        config()->set('cache.default', 'array');

        config()->set('panorama.reports', [
            RevenueByCustomer::class,
            AllOrders::class,
            FinanceOnly::class,
        ]);

        config()->set('panorama.connections', [
            'default' => ['driver' => 'database', 'connection' => 'testing'],
            'crm_api' => ['driver' => 'http', 'base_url' => 'https://crm.test'],
            'fixtures' => ['driver' => 'array', 'tables' => ['orders' => self::ORDERS]],
        ]);

        config()->set('panorama.datasets.register', [
            Orders::class,
            CrmCustomers::class,
            DbOrders::class,
            ShopASales::class,
            ShopBSales::class,
            ShopCSales::class,
        ]);
    }

    /**
     * The connection the whole suite runs on, and therefore the one the
     * database workspace creates its tables in.
     *
     * SQLite in memory by default, so a clone of the repository needs nothing
     * installed. `PANORAMA_DB` points it at a real server instead, which is
     * how CI runs the same tests against the engines whose SQL actually
     * differs — backtick quoting on MySQL, and the CTE wrapper everywhere.
     *
     * The variables are prefixed rather than reusing Laravel's `DB_*` because
     * `phpunit.xml.dist` pins those, and a `<server>` entry wins over the real
     * environment.
     *
     * @return array<string,mixed>
     */
    public static function connection(): array
    {
        $driver = self::driver();

        $server = fn(int $port, string $username): array => [
            'driver' => $driver,
            'host' => (string) env('PANORAMA_DB_HOST', '127.0.0.1'),
            'port' => (string) env('PANORAMA_DB_PORT', (string) $port),
            'database' => (string) env('PANORAMA_DB_DATABASE', 'panorama'),
            'username' => (string) env('PANORAMA_DB_USERNAME', $username),
            'password' => (string) env('PANORAMA_DB_PASSWORD', ''),
            'prefix' => '',
        ];

        return match ($driver) {
            'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'mysql', 'mariadb' => ['charset' => 'utf8mb4'] + $server(3306, 'root'),
            'pgsql' => ['charset' => 'utf8', 'search_path' => 'public'] + $server(5432, 'postgres'),
            default => throw new RuntimeException("PANORAMA_DB=[{$driver}] is not an engine the suite knows."),
        };
    }

    /**
     * The engine the suite is pointed at, read from the environment rather
     * than from a connection, so asking does not open one.
     */
    protected static function driver(): string
    {
        return (string) env('PANORAMA_DB', 'sqlite');
    }

    /**
     * Create and fill the table {@see DbOrders} reads, for the tests that go
     * all the way to a real connection.
     */
    protected function createOrdersTable(): void
    {
        Schema::create('db_orders', function ($table): void {
            $table->integer('id');
            $table->string('customer_id');
            $table->decimal('amount', 10, 2);
            $table->date('created_at');
        });

        foreach (self::ORDERS as $order) {
            DB::table('db_orders')->insert($order);
        }
    }
}
