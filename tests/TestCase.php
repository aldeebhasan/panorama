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

class TestCase extends Orchestra
{
    /**
     * The rows the `orders` dataset serves.
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

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config()->set('cache.default', 'array');

        config()->set('panorama.reports', [
            RevenueByCustomer::class,
            AllOrders::class,
            FinanceOnly::class,
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
