<?php

use Aldeebhasan\Panorama\Dataset\DatasetRegistry;
use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Exceptions\SchemaViolation;
use Aldeebhasan\Panorama\Exceptions\WorkspaceException;
use Aldeebhasan\Panorama\Query\Planner;
use Aldeebhasan\Panorama\Query\Query;
use Aldeebhasan\Panorama\Schema\Schema;
use Aldeebhasan\Panorama\Workspace\DatabaseWorkspace;
use Aldeebhasan\Panorama\Workspace\WorkspaceFactory;
use Illuminate\Support\Facades\DB;

function workspace(): DatabaseWorkspace
{
    $workspace = app(WorkspaceFactory::class)->make('database');

    expect($workspace)->toBeInstanceOf(DatabaseWorkspace::class);

    return $workspace;
}

it('names its engine', function (): void {
    expect(workspace()->name())->toBe('database');
});

it('loads a dataset into a prefixed table and reports the row count', function (): void {
    $workspace = workspace();

    $loaded = $workspace->load(app(DatasetRegistry::class)->get('orders'), Params::make());

    expect($loaded)->toBe(3)
        ->and($workspace->tables())->toBe(['orders' => 'panorama_orders'])
        ->and(DB::table('panorama_orders')->count())->toBe(3);
});

it('creates the columns the schema declares', function (): void {
    // Real tables: SQLite keeps temporary ones in the `temp` schema, which the
    // main schema's introspection cannot see.
    config()->set('panorama.workspaces.database.temporary', false);

    $workspace = workspace();
    app(DatasetRegistry::class)->fake('shapes', [], Schema::make()
        ->primaryKey('id')
        ->int('id')
        ->string('name')
        ->decimal('amount', 4)
        ->bool('paid')
        ->date('day')
        ->datetime('moment')
        ->json('meta')
        ->enum('state', ['a'])
        ->string('maybe')->nullable());

    $workspace->load(app(DatasetRegistry::class)->get('shapes'), Params::make());

    $columns = DB::getSchemaBuilder()->getColumnListing('panorama_shapes');

    expect($columns)->toEqualCanonicalizing(
        ['id', 'name', 'amount', 'paid', 'day', 'moment', 'meta', 'state', 'maybe'],
    );
});

it('drops what it created', function (): void {
    // Real tables rather than temporary ones, so the drop is observable from
    // outside the workspace's own connection state.
    config()->set('panorama.workspaces.database.temporary', false);

    $workspace = workspace();
    $workspace->load(app(DatasetRegistry::class)->get('orders'), Params::make());

    expect(DB::getSchemaBuilder()->hasTable('panorama_orders'))->toBeTrue();

    $workspace->drop();

    expect(DB::getSchemaBuilder()->hasTable('panorama_orders'))->toBeFalse()
        ->and($workspace->tables())->toBe([]);
});

it('survives being dropped twice', function (): void {
    config()->set('panorama.workspaces.database.temporary', false);

    $workspace = workspace();
    $workspace->load(app(DatasetRegistry::class)->get('orders'), Params::make());
    $workspace->drop();
    $workspace->drop();

    expect($workspace->tables())->toBe([]);
});

it('reloads over a table a previous run left behind', function (): void {
    config()->set('panorama.workspaces.database.temporary', false);

    workspace()->load(app(DatasetRegistry::class)->get('orders'), Params::make());

    // A second workspace, as a later run would be, with the table still there.
    $second = workspace();
    $second->load(app(DatasetRegistry::class)->get('orders'), Params::make());

    expect(DB::table('panorama_orders')->count())->toBe(3);

    $second->drop();
});

it('loads in chunks without losing rows', function (): void {
    config()->set('panorama.workspaces.database.chunk', 2);

    $rows = [];
    for ($id = 1; $id <= 7; $id++) {
        $rows[] = ['id' => $id];
    }

    app(DatasetRegistry::class)->fake('many', $rows, Schema::make()->primaryKey('id')->int('id'));

    $workspace = workspace();

    expect($workspace->load(app(DatasetRegistry::class)->get('many'), Params::make()))->toBe(7)
        ->and(DB::table('panorama_many')->count())->toBe(7);
});

it('reports no fast path yet', function (): void {
    expect(workspace()->attach(app(DatasetRegistry::class)->get('orders')))->toBeFalse();
});

it('exposes the sql a plan compiles to', function (): void {
    $workspace = workspace();
    $workspace->load(app(DatasetRegistry::class)->get('orders'), Params::make());

    $plan = app(Planner::class)->plan(Query::from('orders')->where('orders.id', 1));

    expect($workspace->toSql($plan)->sql)
        ->toBe('select * from "panorama_orders" as "orders" where "orders"."id" = ?');
});

it('cleans up when a dataset fails to load', function (): void {
    config()->set('panorama.workspaces.database.temporary', false);

    app(DatasetRegistry::class)->fake('broken', [['id' => 'not an int']], Schema::make()->int('id'));

    try {
        app(Datasets::class)->from('broken')->get();
        $this->fail('the violation did not surface');
    } catch (SchemaViolation $e) {
        expect($e->getMessage())->toContain('Dataset [broken]');
    }

    expect(DB::getSchemaBuilder()->hasTable('panorama_broken'))->toBeFalse();
});

it('drops the tables once the rows have been read', function (): void {
    config()->set('panorama.workspaces.database.temporary', false);

    $result = app(Datasets::class)->from('orders')->result();

    expect(DB::getSchemaBuilder()->hasTable('panorama_orders'))->toBeTrue();

    $result->toArray();

    expect(DB::getSchemaBuilder()->hasTable('panorama_orders'))->toBeFalse();
});

it('drops the tables when the rows are abandoned half way', function (): void {
    config()->set('panorama.workspaces.database.temporary', false);

    $read = (function (): void {
        $result = app(Datasets::class)->from('orders')->result();

        foreach ($result->rows() as $row) {
            break;
        }
    })();

    expect(DB::getSchemaBuilder()->hasTable('panorama_orders'))->toBeFalse();
});

it('refuses an engine it does not have', function (): void {
    app(WorkspaceFactory::class)->make('duckdb');
})->throws(WorkspaceException::class, 'No workspace engine named [duckdb]');

it('uses the configured default engine', function (): void {
    expect(app(WorkspaceFactory::class)->default())->toBe('database');
});
