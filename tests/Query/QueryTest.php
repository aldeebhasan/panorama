<?php

use Aldeebhasan\Panorama\Exceptions\QueryException;
use Aldeebhasan\Panorama\Query\JoinType;
use Aldeebhasan\Panorama\Query\Query;

it('reports only the datasets it names', function (): void {
    $query = Query::from('orders')->join('crm_customers')->leftJoin('vendors');

    expect($query->datasets())->toBe(['orders', 'crm_customers', 'vendors']);
});

it('names the datasets of a union', function (): void {
    expect(Query::union('shop_a_sales', 'shop_b_sales')->datasets())
        ->toBe(['shop_a_sales', 'shop_b_sales']);
});

it('leaves a raw query to declare its own datasets', function (): void {
    expect(Query::raw('select 1')->datasets())->toBe([])
        ->and(Query::raw('select 1')->using('orders')->datasets())->toBe(['orders']);
});

it('counts a dataset named twice only once', function (): void {
    expect(Query::from('orders')->join('orders', 'orders.id', 'orders.id')->datasets())
        ->toBe(['orders']);
});

it('tells the three query shapes apart', function (): void {
    expect(Query::from('orders')->isRaw())->toBeFalse()
        ->and(Query::from('orders')->isUnion())->toBeFalse()
        ->and(Query::union('a', 'b')->isUnion())->toBeTrue()
        ->and(Query::raw('select 1')->isRaw())->toBeTrue();
});

it('records joins with their type and keys', function (): void {
    $query = Query::from('orders')
        ->join('crm_customers', 'orders.customer_id', 'crm_customers.id')
        ->leftJoin('vendors');

    [$inner, $left] = $query->joins();

    expect($inner->type)->toBe(JoinType::Inner)
        ->and($inner->isResolved())->toBeTrue()
        ->and($inner->localKey)->toBe('orders.customer_id')
        ->and($left->type)->toBe(JoinType::Left)
        ->and($left->isResolved())->toBeFalse();
});

it('accepts a two argument where as an equality', function (): void {
    $query = Query::from('orders')->where('status', 'draft');

    expect($query->wheres()[0]->operator)->toBe('=')
        ->and($query->wheres()[0]->values)->toBe(['draft']);
});

it('normalizes the operator case', function (): void {
    expect(Query::from('orders')->where('name', 'LIKE', 'a%')->wheres()[0]->operator)->toBe('like');
});

it('rejects an operator that is not whitelisted', function (): void {
    Query::from('orders')->where('amount', 'DROP TABLE', 1);
})->throws(QueryException::class, 'is not a supported comparison operator');

it('rejects a sort direction that is not asc or desc', function (): void {
    Query::from('orders')->orderBy('id', 'sideways');
})->throws(QueryException::class, 'is not a sort direction');

it('rejects a column that is not an identifier', function (string $column): void {
    Query::from('orders')->where($column, 1);
})->with([
    'injection' => ['id); drop table users; --'],
    'expression' => ['sum(amount)'],
    'three parts' => ['db.orders.id'],
    'quoted' => ['"id"'],
    'empty' => [''],
    'space' => ['order id'],
])->throws(QueryException::class, 'is not a valid column');

it('accepts the identifier shapes a report actually writes', function (string $column): void {
    expect(Query::from('orders')->where($column, 1)->wheres()[0]->column)->toBe(trim($column));
})->with([
    'bare' => ['amount'],
    'qualified' => ['orders.amount'],
    'underscored' => ['_internal_id'],
    'padded' => ['  amount  '],
]);

it('rejects a join that declares one key but not the other', function (): void {
    Query::from('orders')->join('crm_customers', 'orders.customer_id');
})->throws(QueryException::class, 'declares one key but not the other');

it('keeps raw expressions as the user wrote them', function (): void {
    $query = Query::from('orders')->select('crm_customers.name', 'sum(orders.amount) as revenue');

    expect($query->selects())->toBe(['crm_customers.name', 'sum(orders.amount) as revenue']);
});

it('clamps a negative limit and offset to zero', function (): void {
    $query = Query::from('orders')->limit(-5)->offset(-1);

    expect($query->getLimit())->toBe(0)->and($query->getOffset())->toBe(0);
});

it('collects every condition shape', function (): void {
    $query = Query::from('orders')
        ->where('amount', '>', 10)
        ->whereIn('status', ['draft', 'sent'])
        ->whereNotIn('country', ['XX'])
        ->whereBetween('created_at', '2026-01-01', '2026-12-31')
        ->whereNull('deleted_at')
        ->whereNotNull('customer_id')
        ->whereRaw('amount <> ?', [0]);

    expect(array_map(fn($c) => $c->kind, $query->wheres()))
        ->toBe(['comparison', 'in', 'notIn', 'between', 'null', 'notNull', 'raw']);
});
