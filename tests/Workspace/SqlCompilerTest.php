<?php

use Aldeebhasan\Panorama\Exceptions\QueryException;
use Aldeebhasan\Panorama\Query\Plan;
use Aldeebhasan\Panorama\Query\Query;
use Aldeebhasan\Panorama\Workspace\Compiled;
use Aldeebhasan\Panorama\Workspace\SqlCompiler;

/**
 * @param array<string,string> $tables
 */
function compile(Query $query, array $tables = [], string $driver = 'sqlite'): Compiled
{
    return (new SqlCompiler($driver))->compile(new Plan($query, []), $tables);
}

/**
 * @return array<string,string>
 */
function loaded(string ...$datasets): array
{
    $tables = [];
    foreach ($datasets as $dataset) {
        $tables[$dataset] = 'panorama_' . $dataset;
    }

    return $tables;
}

it('aliases the workspace table back to the dataset name', function (): void {
    $compiled = compile(Query::from('orders'), loaded('orders'));

    expect($compiled->sql)->toBe('select * from "panorama_orders" as "orders"');
});

it('leaves a select expression exactly as the report wrote it', function (): void {
    $compiled = compile(
        Query::from('orders')->select('crm_customers.name', 'sum(orders.amount) as revenue'),
        loaded('orders'),
    );

    expect($compiled->sql)->toContain('select crm_customers.name, sum(orders.amount) as revenue from');
});

it('compiles a join on the resolved keys', function (): void {
    $compiled = compile(
        Query::from('orders')->join('crm_customers', 'orders.customer_id', 'crm_customers.id'),
        loaded('orders', 'crm_customers'),
    );

    expect($compiled->sql)->toBe(
        'select * from "panorama_orders" as "orders" inner join "panorama_crm_customers" as "crm_customers" '
        . 'on "orders"."customer_id" = "crm_customers"."id"',
    );
});

it('compiles a left join', function (): void {
    $compiled = compile(
        Query::from('orders')->leftJoin('crm_customers', 'orders.customer_id', 'crm_customers.id'),
        loaded('orders', 'crm_customers'),
    );

    expect($compiled->sql)->toContain('left join "panorama_crm_customers" as "crm_customers"');
});

it('binds every value rather than inlining it', function (): void {
    $compiled = compile(
        Query::from('orders')
            ->where('orders.status', 'draft')
            ->whereIn('orders.id', [1, 2])
            ->whereBetween('orders.created_at', '2026-01-01', '2026-12-31')
            ->whereNull('orders.deleted_at')
            ->whereNotNull('orders.customer_id')
            ->whereRaw('amount <> ?', [0]),
        loaded('orders'),
    );

    expect($compiled->sql)->toContain(
        'where "orders"."status" = ? and "orders"."id" in (?, ?) and "orders"."created_at" between ? and ? '
        . 'and "orders"."deleted_at" is null and "orders"."customer_id" is not null and (amount <> ?)',
    )
        ->and($compiled->bindings)->toBe(['draft', 1, 2, '2026-01-01', '2026-12-31', 0]);
});

it('compiles an empty in clause to a contradiction rather than invalid sql', function (): void {
    expect(compile(Query::from('orders')->whereIn('orders.id', []), loaded('orders'))->sql)
        ->toContain('where 1 = 0')
        ->and(compile(Query::from('orders')->whereNotIn('orders.id', []), loaded('orders'))->sql)
        ->toContain('where 1 = 1');
});

it('compiles grouping, having, ordering and paging', function (): void {
    $compiled = compile(
        Query::from('orders')
            ->select('orders.customer_id', 'sum(orders.amount) as revenue')
            ->groupBy('orders.customer_id')
            ->havingRaw('sum(orders.amount) > ?', [100])
            ->orderBy('orders.customer_id', 'desc')
            ->limit(10)
            ->offset(20),
        loaded('orders'),
    );

    expect($compiled->sql)->toContain('group by "orders"."customer_id"')
        ->toContain('having (sum(orders.amount) > ?)')
        ->toContain('order by "orders"."customer_id" desc')
        ->toContain('limit 10 offset 20')
        ->and($compiled->bindings)->toBe([100]);
});

it('compiles a union as a subquery that can be filtered', function (): void {
    $compiled = compile(
        Query::union('shop_a_sales', 'shop_b_sales')->select('sum(units) as units'),
        loaded('shop_a_sales', 'shop_b_sales'),
    );

    expect($compiled->sql)->toBe(
        'select sum(units) as units from ('
        . 'select * from "panorama_shop_a_sales" as "shop_a_sales" union all '
        . 'select * from "panorama_shop_b_sales" as "shop_b_sales"'
        . ') as "unioned"',
    );
});

it('binds the source tag of a union', function (): void {
    $compiled = compile(
        Query::union('shop_a_sales', 'shop_b_sales')->tagSource('origin'),
        loaded('shop_a_sales', 'shop_b_sales'),
    );

    expect($compiled->sql)->toContain('select ? as "origin", * from "panorama_shop_a_sales"')
        ->and($compiled->bindings)->toBe(['shop_a_sales', 'shop_b_sales']);
});

it('wraps raw sql in common table expressions named after the datasets', function (): void {
    $compiled = compile(
        Query::raw('select * from orders o join crm_customers c on c.id = o.customer_id'),
        loaded('orders', 'crm_customers'),
    );

    expect($compiled->sql)->toBe(
        'with "orders" as (select * from "panorama_orders"), '
        . '"crm_customers" as (select * from "panorama_crm_customers") '
        . 'select * from orders o join crm_customers c on c.id = o.customer_id',
    );
});

it('keeps the ctes a raw query brought of its own', function (): void {
    $compiled = compile(
        Query::raw('WITH big as (select * from orders where amount > 10) select * from big'),
        loaded('orders'),
    );

    expect($compiled->sql)->toBe(
        'with "orders" as (select * from "panorama_orders"), '
        . 'big as (select * from orders where amount > 10) select * from big',
    );
});

it('refuses a raw query that opens with a recursive cte', function (): void {
    compile(Query::raw('with recursive t as (select 1) select * from t'), loaded('orders'));
})->throws(QueryException::class, 'cannot open with [with recursive]');

it('quotes with backticks on mysql', function (): void {
    expect(compile(Query::from('orders')->where('orders.id', 1), loaded('orders'), 'mysql')->sql)
        ->toBe('select * from `panorama_orders` as `orders` where `orders`.`id` = ?');
});

it('escapes a quote inside an identifier', function (): void {
    expect(compile(Query::from('we"ird'), ['we"ird' => 'panorama_weird'])->sql)
        ->toContain('as "we""ird"');
});

it('refuses to compile a query against a dataset that was never loaded', function (): void {
    compile(Query::from('orders'), []);
})->throws(QueryException::class, 'was never loaded into the workspace');
