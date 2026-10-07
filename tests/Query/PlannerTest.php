<?php

use Aldeebhasan\Panorama\Dataset\DatasetRegistry;
use Aldeebhasan\Panorama\Exceptions\IncompatibleJoinKeys;
use Aldeebhasan\Panorama\Exceptions\IncompatibleUnion;
use Aldeebhasan\Panorama\Exceptions\QueryException;
use Aldeebhasan\Panorama\Exceptions\UnknownDataset;
use Aldeebhasan\Panorama\Query\Planner;
use Aldeebhasan\Panorama\Query\Query;
use Aldeebhasan\Panorama\Schema\Schema;

function planner(): Planner
{
    return app(Planner::class);
}

/*
|--------------------------------------------------------------------------
| join key inference
|--------------------------------------------------------------------------
*/

it('infers the join keys from the declared relation', function (): void {
    $plan = planner()->plan(Query::from('orders')->join('crm_customers'));

    $join = $plan->query->joins()[0];

    expect($join->localKey)->toBe('orders.customer_id')
        ->and($join->foreignKey)->toBe('crm_customers.id');
});

it('infers the relation in the reverse direction too', function (): void {
    $plan = planner()->plan(Query::from('crm_customers')->join('orders'));

    $join = $plan->query->joins()[0];

    expect($join->localKey)->toBe('crm_customers.id')
        ->and($join->foreignKey)->toBe('orders.customer_id');
});

it('keeps the keys a join declared for itself', function (): void {
    $plan = planner()->plan(
        Query::from('orders')->join('crm_customers', 'orders.customer_id', 'crm_customers.id'),
    );

    expect($plan->query->joins()[0]->localKey)->toBe('orders.customer_id');
});

it('says what to do when no relation is declared', function (): void {
    planner()->plan(Query::from('orders')->join('shop_a_sales'));
})->throws(
    IncompatibleJoinKeys::class,
    'No relation is declared between [orders] and [shop_a_sales]. Add references() to one of the schemas, or name the keys on the join.',
);

it('says which keys to pick when two datasets are related twice', function (): void {
    app(DatasetRegistry::class)->fake('orders', [], Schema::make()
        ->int('id')
        ->string('customer_id')->references('crm_customers.id')
        ->string('billing_id')->references('crm_customers.id'));

    planner()->plan(Query::from('orders')->join('crm_customers'));
})->throws(IncompatibleJoinKeys::class, 'are related more than once');

/*
|--------------------------------------------------------------------------
| join key types
|--------------------------------------------------------------------------
*/

it('refuses a join whose keys could never match', function (): void {
    // crm_customers.id is a string, db_orders.id is an int.
    planner()->plan(Query::from('db_orders')->join('crm_customers', 'db_orders.id', 'crm_customers.id'));
})->throws(
    IncompatibleJoinKeys::class,
    'Cannot join [db_orders.id] (int) on [crm_customers.id] (string): the declared types are not comparable, so the join would silently return nothing.',
);

it('allows a join between numbers of different precision', function (): void {
    app(DatasetRegistry::class)->fake('a', [], Schema::make()->int('ref'));
    app(DatasetRegistry::class)->fake('b', [], Schema::make()->decimal('ref', 0));

    $plan = planner()->plan(Query::from('a')->join('b', 'a.ref', 'b.ref'));

    expect($plan->names())->toBe(['a', 'b']);
});

it('allows a join between an enum and a string', function (): void {
    app(DatasetRegistry::class)->fake('a', [], Schema::make()->enum('ref', ['x', 'y']));
    app(DatasetRegistry::class)->fake('b', [], Schema::make()->string('ref'));

    expect(planner()->plan(Query::from('a')->join('b', 'a.ref', 'b.ref'))->names())->toBe(['a', 'b']);
});

it('refuses a join on a json field', function (): void {
    app(DatasetRegistry::class)->fake('a', [], Schema::make()->json('ref'));
    app(DatasetRegistry::class)->fake('b', [], Schema::make()->json('ref'));

    planner()->plan(Query::from('a')->join('b', 'a.ref', 'b.ref'));
})->throws(IncompatibleJoinKeys::class, 'not comparable');

it('refuses a join on a field the schema does not declare', function (): void {
    planner()->plan(Query::from('orders')->join('crm_customers', 'orders.nope', 'crm_customers.id'));
})->throws(QueryException::class, 'The dataset [orders] declares no field named [nope]');

it('requires join keys to name their dataset', function (): void {
    planner()->plan(Query::from('orders')->join('crm_customers', 'customer_id', 'id'));
})->throws(QueryException::class, 'must name its dataset, as [dataset.field]');

/*
|--------------------------------------------------------------------------
| unions
|--------------------------------------------------------------------------
*/

it('accepts a union of same shaped datasets', function (): void {
    expect(planner()->plan(Query::union('shop_a_sales', 'shop_b_sales'))->names())
        ->toBe(['shop_a_sales', 'shop_b_sales']);
});

it('refuses a union whose members have different fields', function (): void {
    planner()->plan(Query::union('shop_a_sales', 'shop_c_sales'));
})->throws(
    IncompatibleUnion::class,
    'Cannot union [shop_a_sales] with [shop_c_sales]: it is missing [units] and adds [currency]. Map the fields explicitly in the schemas first.',
);

it('refuses a union whose members type a field differently', function (): void {
    app(DatasetRegistry::class)->fake('shop_d_sales', [], Schema::make()
        ->string('sku')
        ->int('units')
        ->string('revenue'));

    planner()->plan(Query::union('shop_a_sales', 'shop_d_sales'));
})->throws(
    IncompatibleUnion::class,
    'the field [revenue] is decimal in one and string in the other',
);

it('refuses a union of one dataset', function (): void {
    planner()->plan(Query::union('shop_a_sales'));
})->throws(IncompatibleUnion::class, 'needs at least two datasets');

/*
|--------------------------------------------------------------------------
| raw sql
|--------------------------------------------------------------------------
*/

it('finds the datasets a raw query mentions', function (): void {
    $plan = planner()->plan(Query::raw('select * from orders o join crm_customers c on c.id = o.customer_id'));

    expect($plan->names())->toEqualCanonicalizing(['orders', 'crm_customers']);
});

it('prefers the datasets a raw query declares', function (): void {
    $plan = planner()->plan(Query::raw('select * from whatever')->using('orders'));

    expect($plan->names())->toBe(['orders']);
});

it('refuses a raw query that mentions no known dataset', function (): void {
    planner()->plan(Query::raw('select 1'));
})->throws(QueryException::class, 'mentions no registered dataset');

/*
|--------------------------------------------------------------------------
| what gets loaded
|--------------------------------------------------------------------------
*/

it('collects only the datasets the query names', function (): void {
    $plan = planner()->plan(Query::from('orders')->join('crm_customers'));

    expect($plan->names())->toBe(['orders', 'crm_customers'])
        ->and($plan->datasets)->toHaveCount(2);
});

it('refuses a query that names no dataset at all', function (): void {
    planner()->plan(Query::from(''));
})->throws(UnknownDataset::class);
