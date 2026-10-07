<?php

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Schema\Schema;

/*
 * The suite every workspace engine has to pass.
 *
 * Datasets and reports are identical across engines, so these tests describe
 * behaviour only: load, join, union, aggregate, null handling and ordering.
 * Nothing here may mention SQL, temporary tables, or anything else that is one
 * engine's business.
 */
beforeEach(function (): void {
    $this->datasets = app(Datasets::class);

    // The CRM rows the merges join against, without going near HTTP.
    $this->datasets->fake('crm_customers', [
        ['ext_id' => 'c-1', 'custName' => 'Ada', 'country' => 'DE'],
        ['ext_id' => 'c-2', 'custName' => 'Linus', 'country' => null],
        ['ext_id' => 'c-9', 'custName' => 'Grace', 'country' => 'US'],
    ]);
});

it('reads one dataset standalone', function (string $engine): void {
    $rows = $this->datasets->from('orders')->on($engine)->orderBy('orders.id')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows[0]['id'])->toEqual(1)
        ->and($rows[0]['customer_id'])->toBe('c-1');
})->with('engines');

it('enriches rows through a declared relation', function (string $engine): void {
    $rows = $this->datasets->from('orders')
        ->on($engine)
        ->join('crm_customers')
        ->select('orders.id', 'crm_customers.name')
        ->orderBy('orders.id')
        ->get();

    expect($rows)->toBe([
        ['id' => 1, 'name' => 'Ada'],
        ['id' => 2, 'name' => 'Linus'],
        ['id' => 3, 'name' => 'Ada'],
    ]);
})->with('engines');

it('aggregates across a join', function (string $engine): void {
    $rows = $this->datasets->from('orders')
        ->on($engine)
        ->join('crm_customers')
        ->select('crm_customers.name', 'sum(orders.amount) as revenue')
        ->groupBy('crm_customers.name')
        ->orderBy('name')
        ->get();

    // Within a tolerance on purpose: the suite has to hold on any engine, and
    // engines differ in what they sum a decimal column into. SQLite stores
    // DECIMAL as NUMERIC and adds it in binary floating point, so this comes
    // back as 49.989999999999995 there.
    expect($rows)->toHaveCount(2)
        ->and($rows[0]['name'])->toBe('Ada')
        ->and((float) $rows[0]['revenue'])->toEqualWithDelta(49.99, 0.0001)
        ->and($rows[1]['name'])->toBe('Linus')
        ->and((float) $rows[1]['revenue'])->toEqualWithDelta(5.5, 0.0001);
})->with('engines');

it('drops unmatched rows on an inner join and keeps them on a left join', function (string $engine): void {
    $this->datasets->fake('orders', [
        ['id' => 1, 'customer_id' => 'c-1', 'amount' => '10.00', 'created_at' => '2026-01-01'],
        ['id' => 2, 'customer_id' => 'unknown', 'amount' => '20.00', 'created_at' => '2026-01-02'],
    ]);

    $inner = $this->datasets->from('orders')->on($engine)->join('crm_customers')->get();

    $left = $this->datasets->from('orders')
        ->on($engine)
        ->leftJoin('crm_customers')
        ->select('orders.id', 'crm_customers.name')
        ->orderBy('orders.id')
        ->get();

    expect($inner)->toHaveCount(1)
        ->and($left)->toHaveCount(2)
        ->and($left[1]['name'])->toBeNull();
})->with('engines');

it('appends same shaped datasets to each other', function (string $engine): void {
    $rows = $this->datasets->union('shop_a_sales', 'shop_b_sales')->on($engine)->orderBy('sku')->get();

    expect(array_column($rows, 'sku'))->toBe(['A-1', 'A-2', 'B-1']);
})->with('engines');

it('tags each row of a union with the dataset it came from', function (string $engine): void {
    $rows = $this->datasets->union('shop_a_sales', 'shop_b_sales')
        ->on($engine)
        ->tagSource('source')
        ->orderBy('sku')
        ->get();

    expect(array_column($rows, 'source'))->toBe(['shop_a_sales', 'shop_a_sales', 'shop_b_sales'])
        ->and(array_keys($rows[0]))->toContain('source', 'sku', 'units', 'revenue');
})->with('engines');

it('aggregates over a union', function (string $engine): void {
    $rows = $this->datasets->union('shop_a_sales', 'shop_b_sales')
        ->on($engine)
        ->select('sum(units) as units')
        ->get();

    expect((int) $rows[0]['units'])->toBe(10);
})->with('engines');

it('keeps nulls as nulls through the workspace', function (string $engine): void {
    $rows = $this->datasets->from('crm_customers')->on($engine)->orderBy('id')->get();

    expect($rows[0]['country'])->toBe('DE')
        ->and($rows[1]['country'])->toBeNull();
})->with('engines');

it('filters, sorts and pages', function (string $engine): void {
    $rows = $this->datasets->from('orders')
        ->on($engine)
        ->where('orders.amount', '>', 5)
        ->orderBy('orders.amount', 'desc')
        ->limit(1)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and((float) $rows[0]['amount'])->toBe(30.0);
})->with('engines');

it('applies every condition shape', function (string $engine): void {
    $rows = $this->datasets->from('orders')
        ->on($engine)
        ->whereIn('orders.customer_id', ['c-1', 'c-2'])
        ->whereNotIn('orders.id', [3])
        ->whereBetween('orders.created_at', '2026-01-01', '2026-12-31')
        ->whereNotNull('orders.amount')
        ->orderBy('orders.id')
        ->get();

    expect(array_column($rows, 'id'))->toEqual([1, 2]);
})->with('engines');

it('matches nothing for an empty in clause', function (string $engine): void {
    expect($this->datasets->from('orders')->on($engine)->whereIn('orders.id', [])->get())->toBe([]);
})->with('engines');

it('passes the run filters down to every dataset that declares them', function (string $engine): void {
    $rows = $this->datasets->from('orders')
        ->on($engine)
        ->params(['from' => '2026-02-01'])
        ->orderBy('orders.id')
        ->get();

    expect(array_column($rows, 'id'))->toEqual([2, 3]);
})->with('engines');

it('loads only the datasets the query names', function (string $engine): void {
    $loaded = $this->datasets->from('orders')->on($engine)->join('crm_customers')->loaded();

    expect($loaded)->toHaveKeys(['orders', 'crm_customers'])
        ->and($loaded)->not->toHaveKey('shop_a_sales')
        ->and($loaded['orders'])->toBe(3)
        ->and($loaded['crm_customers'])->toBe(3);
})->with('engines');

it('round trips every field type', function (string $engine): void {
    $this->datasets->fake(
        'everything',
        [[
            'text' => 'hello',
            'whole' => '42',
            'money' => '19.994',
            'flag' => 'yes',
            'day' => '2026-01-15',
            'moment' => '2026-01-15T10:00:00+02:00',
            'blob' => '{"a":1}',
            'state' => 'sent',
            'nothing' => null,
        ]],
        Schema::make()
            ->string('text')
            ->int('whole')
            ->decimal('money', 2)
            ->bool('flag')
            ->date('day')
            ->datetime('moment')
            ->json('blob')
            ->enum('state', ['draft', 'sent'])
            ->string('nothing')->nullable(),
    );

    $row = $this->datasets->from('everything')->on($engine)->first();

    expect($row)->not->toBeNull()
        ->and($row['text'])->toBe('hello')
        ->and((int) $row['whole'])->toBe(42)
        ->and((float) $row['money'])->toBe(19.99)
        ->and((bool) $row['flag'])->toBeTrue()
        ->and($row['day'])->toContain('2026-01-15')
        ->and($row['moment'])->toContain('2026-01-15 08:00:00')
        ->and(json_decode((string) $row['blob'], true))->toBe(['a' => 1])
        ->and($row['state'])->toBe('sent')
        ->and($row['nothing'])->toBeNull();
})->with('engines');

it('streams the merged rows rather than materializing them', function (string $engine): void {
    $result = $this->datasets->from('orders')->on($engine)->orderBy('orders.id')->result();

    expect($result->isConsumed())->toBeFalse();

    foreach ($result->rows() as $row) {
        expect($row['id'])->toEqual(1);

        break;
    }

    expect($result->isConsumed())->toBeTrue();
})->with('engines');

it('reports the columns before any row is consumed', function (string $engine): void {
    $result = $this->datasets->from('orders')
        ->on($engine)
        ->select('orders.id', 'orders.amount')
        ->result();

    expect($result->columns())->toBe(['id', 'amount'])
        ->and($result->toArray())->toHaveCount(3);
})->with('engines');

it('runs hand written sql against the loaded datasets', function (string $engine): void {
    $rows = $this->datasets
        ->sql('select c.name, count(*) as orders from orders o join crm_customers c on c.id = o.customer_id group by c.name order by c.name')
        ->on($engine)
        ->get();

    expect($rows)->toBe([
        ['name' => 'Ada', 'orders' => 2],
        ['name' => 'Linus', 'orders' => 1],
    ]);
})->with('engines');

it('binds the values of a hand written query', function (string $engine): void {
    $rows = $this->datasets
        ->sql('select id from orders where customer_id = ? order by id', ['c-1'])
        ->on($engine)
        ->get();

    expect(array_column($rows, 'id'))->toEqual([1, 3]);
})->with('engines');
