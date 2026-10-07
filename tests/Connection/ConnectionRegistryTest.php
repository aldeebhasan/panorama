<?php

use Aldeebhasan\Panorama\Connection\ArrayConnection;
use Aldeebhasan\Panorama\Connection\ConnectionRegistry;
use Aldeebhasan\Panorama\Connection\DatabaseConnection;
use Aldeebhasan\Panorama\Connection\HttpConnection;
use Aldeebhasan\Panorama\Exceptions\ConnectionException;

/**
 * @param array<string,mixed> $connections
 */
function registry(array $connections): ConnectionRegistry
{
    return new ConnectionRegistry(
        $connections,
        app(Illuminate\Database\ConnectionResolverInterface::class),
        app(Illuminate\Http\Client\Factory::class),
    );
}

it('builds a connection for each driver', function (): void {
    $registry = registry([
        'db' => ['driver' => 'database', 'connection' => 'testing'],
        'api' => ['driver' => 'http', 'base_url' => 'https://api.test'],
        'memory' => ['driver' => 'array', 'tables' => ['orders' => [['id' => 1]]]],
    ]);

    expect($registry->get('db'))->toBeInstanceOf(DatabaseConnection::class)
        ->and($registry->get('api'))->toBeInstanceOf(HttpConnection::class)
        ->and($registry->get('memory'))->toBeInstanceOf(ArrayConnection::class);
});

it('carries the configured options onto the connection', function (): void {
    $registry = registry([
        'db' => ['driver' => 'database', 'connection' => 'testing'],
        'api' => ['driver' => 'http', 'base_url' => 'https://api.test'],
    ]);

    $database = $registry->get('db');
    $http = $registry->get('api');

    expect($database)->toBeInstanceOf(DatabaseConnection::class)
        ->and($http)->toBeInstanceOf(HttpConnection::class);

    expect($database->target())->toBe('testing')
        ->and($database->name())->toBe('db')
        ->and($http->baseUrl())->toBe('https://api.test');
});

it('builds each connection once and shares it', function (): void {
    $registry = registry(['db' => ['driver' => 'database']]);

    expect($registry->get('db'))->toBe($registry->get('db'));
});

it('lets a registered connection replace a configured one', function (): void {
    $registry = registry(['orders' => ['driver' => 'database']]);
    $fake = new ArrayConnection('orders', ['orders' => [['id' => 7]]]);

    $registry->register($fake);

    expect($registry->get('orders'))->toBe($fake);
});

it('reports which connections it knows about', function (): void {
    $registry = registry(['a' => ['driver' => 'database'], 'b' => ['driver' => 'database']]);
    $registry->register(new ArrayConnection('c'));

    expect($registry->names())->toEqualCanonicalizing(['a', 'b', 'c'])
        ->and($registry->has('a'))->toBeTrue()
        ->and($registry->has('zz'))->toBeFalse();
});

it('names the configured connections when one is missing', function (): void {
    registry(['mysql' => ['driver' => 'database'], 'crm_api' => ['driver' => 'http', 'base_url' => 'x']])
        ->get('typo');
})->throws(
    ConnectionException::class,
    'No connection is configured under the name [typo]. Configured connections are [crm_api, mysql].',
);

it('rejects an unknown driver', function (): void {
    registry(['weird' => ['driver' => 'telepathy']])->get('weird');
})->throws(ConnectionException::class, 'declares the unknown driver [telepathy]');

it('rejects an http connection with no base url', function (): void {
    registry(['api' => ['driver' => 'http']])->get('api');
})->throws(ConnectionException::class, 'is missing the required [base_url] option');

it('serves rows out of an array connection', function (): void {
    $connection = new ArrayConnection('memory', ['orders' => [['id' => 1], ['id' => 2]]]);

    expect($connection->driver())->toBe('array')
        ->and($connection->tables())->toBe(['orders'])
        ->and($connection->has('orders'))->toBeTrue()
        ->and($connection->has('nope'))->toBeFalse()
        ->and(iterator_to_array(new ArrayIterator((array) $connection->rows('orders'))))->toHaveCount(2)
        ->and($connection->rows('nope'))->toBe([]);
});

it('accepts rows put on an array connection after it is built', function (): void {
    $connection = (new ArrayConnection('memory'))->put('orders', [['id' => 1]]);

    expect($connection->rows('orders'))->toBe([['id' => 1]]);
});

it('reads a real table through a database connection', function (): void {
    $this->createOrdersTable();

    $connection = registry(['db' => ['driver' => 'database', 'connection' => 'testing']])->get('db');

    expect($connection)->toBeInstanceOf(DatabaseConnection::class)
        ->and($connection->table('db_orders')->count())->toBe(3)
        ->and($connection->select('select count(*) as c from db_orders')[0]->c)->toBe(3);
});
