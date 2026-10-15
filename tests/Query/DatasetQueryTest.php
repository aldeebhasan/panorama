<?php

use Aldeebhasan\Panorama\Dataset\Dataset;
use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Exceptions\IncompatibleJoinKeys;
use Aldeebhasan\Panorama\Exceptions\IncompatibleUnion;
use Aldeebhasan\Panorama\Exceptions\QueryException;
use Aldeebhasan\Panorama\Exceptions\UnknownDataset;
use Aldeebhasan\Panorama\Query\Aggregate;
use Aldeebhasan\Panorama\Schema\Schema;

beforeEach(function (): void {
    $this->datasets = app(Datasets::class);

    // The CRM rows the queries join against, without going near HTTP.
    $this->datasets->fake('crm_customers', [
        ['ext_id' => 'c-1', 'custName' => 'Ada', 'country' => 'DE'],
        ['ext_id' => 'c-2', 'custName' => 'Linus', 'country' => null],
        ['ext_id' => 'c-9', 'custName' => 'Grace', 'country' => 'US'],
    ]);

    $this->datasets->fake('people', [
        ['id' => 1, 'name' => 'Ada', 'city' => 'Berlin'],
        ['id' => 2, 'name' => 'ada', 'city' => null],
        ['id' => 3, 'name' => 'Linus', 'city' => 'Helsinki'],
    ], Schema::make()->primaryKey('id')->int('id')->string('name')->string('city')->nullable());
});

/*
|--------------------------------------------------------------------------
| reading one dataset
|--------------------------------------------------------------------------
*/

it('reads one dataset standalone', function (): void {
    $rows = $this->datasets->from('orders')->orderBy('orders.id')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toBe(['id' => 1, 'customer_id' => 'c-1', 'amount' => 19.99, 'created_at' => '2026-01-15']);
});

it('keeps nulls as nulls', function (): void {
    $rows = $this->datasets->from('crm_customers')->orderBy('id')->get();

    expect($rows[0]['country'])->toBe('DE')
        ->and($rows[1]['country'])->toBeNull();
});

it('selects and aliases columns', function (): void {
    $rows = $this->datasets->from('people')
        ->select('people.id as person', 'name')
        ->orderBy('person', 'desc')
        ->get();

    expect($rows[0])->toBe(['person' => 3, 'name' => 'Linus']);
});

it('declares its columns even when no row matches', function (): void {
    $result = $this->datasets->from('people')->where('id', '>', 99)->result();

    expect($result->columns())->toBe(['id', 'name', 'city'])
        ->and($result->toArray())->toBe([]);
});

it('streams the rows rather than materializing them', function (): void {
    $result = $this->datasets->from('orders')->result();

    expect($result->isConsumed())->toBeFalse();

    foreach ($result->rows() as $row) {
        expect($row['id'])->toBe(1);

        break;
    }

    expect($result->isConsumed())->toBeTrue();
});

it('passes the run filters down to every dataset that declares them', function (): void {
    $rows = $this->datasets->from('orders')->params(['from' => '2026-02-01'])->orderBy('orders.id')->get();

    expect(array_column($rows, 'id'))->toBe([2, 3]);
});

it('counts the rows each dataset contributes', function (): void {
    $loaded = $this->datasets->from('orders')->join('crm_customers')->loaded();

    expect($loaded)->toBe(['orders' => 3, 'crm_customers' => 3]);
});

/*
|--------------------------------------------------------------------------
| filtering
|--------------------------------------------------------------------------
*/

it('filters, sorts and pages', function (): void {
    $rows = $this->datasets->from('orders')
        ->where('orders.amount', '>', 5)
        ->orderBy('orders.amount', 'desc')
        ->limit(1)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['amount'])->toBe(30.0);
});

it('applies every condition shape', function (): void {
    $rows = $this->datasets->from('orders')
        ->whereIn('orders.customer_id', ['c-1', 'c-2'])
        ->whereNotIn('orders.id', [3])
        ->whereBetween('orders.created_at', '2026-01-01', '2026-12-31')
        ->whereNotNull('orders.amount')
        ->orderBy('orders.id')
        ->get();

    expect(array_column($rows, 'id'))->toBe([1, 2]);
});

it('matches nothing for an empty in clause', function (): void {
    expect($this->datasets->from('orders')->whereIn('orders.id', [])->get())->toBe([]);
});

it('matches nothing when either side of a comparison is null', function (): void {
    $rows = $this->datasets->from('people')->where('city', '!=', 'Berlin')->get();

    expect(array_column($rows, 'id'))->toBe([3]);
});

it('matches nothing for not in a list holding a null', function (): void {
    expect($this->datasets->from('people')->whereNotIn('id', [1, null])->get())->toBe([]);
});

it('treats like as case sensitive and ilike as not', function (): void {
    $like = $this->datasets->from('people')->where('name', 'like', 'a%')->get();
    $ilike = $this->datasets->from('people')->where('name', 'ilike', 'a_a')->get();
    $not = $this->datasets->from('people')->where('name', 'not like', '%a')->get();

    expect(array_column($like, 'id'))->toBe([2])
        ->and(array_column($ilike, 'id'))->toBe([1, 2])
        ->and(array_column($not, 'id'))->toBe([3]);
});

it('takes like wildcards literally everywhere else', function (): void {
    $this->datasets->fake('codes', [['code' => 'a.b'], ['code' => 'axb']], Schema::make()->string('code'));

    expect($this->datasets->from('codes')->where('code', 'like', 'a.b')->get())->toBe([['code' => 'a.b']]);
});

it('filters with a callback over the qualified row', function (): void {
    $rows = $this->datasets->from('orders')
        ->join('crm_customers')
        ->filter(fn(array $row): bool => $row['crm_customers.name'] === 'Ada' && $row['orders.amount'] > 20)
        ->select('orders.id')
        ->get();

    expect($rows)->toBe([['id' => 3]]);
});

/*
|--------------------------------------------------------------------------
| ordering and paging
|--------------------------------------------------------------------------
*/

it('sorts nulls first ascending and last descending', function (): void {
    $asc = $this->datasets->from('people')->orderBy('city')->get();
    $desc = $this->datasets->from('people')->orderBy('city', 'desc')->get();

    expect(array_column($asc, 'id'))->toBe([2, 1, 3])
        ->and(array_column($desc, 'id'))->toBe([3, 1, 2]);
});

it('keeps load order between rows the ordering cannot tell apart', function (): void {
    $this->datasets->fake('ties', [
        ['id' => 1, 'rank' => 2],
        ['id' => 2, 'rank' => 1],
        ['id' => 3, 'rank' => 2],
        ['id' => 4, 'rank' => 1],
    ], Schema::make()->int('id')->int('rank'));

    expect(array_column($this->datasets->from('ties')->orderBy('rank')->get(), 'id'))->toBe([2, 4, 1, 3]);
});

it('sorts by a field it does not select', function (): void {
    $rows = $this->datasets->from('orders')->select('orders.id')->orderBy('orders.amount', 'desc')->get();

    expect(array_column($rows, 'id'))->toBe([3, 1, 2]);
});

it('skips with an offset', function (): void {
    $rows = $this->datasets->from('people')->orderBy('id')->offset(1)->limit(1)->get();

    expect(array_column($rows, 'id'))->toBe([2]);
});

it('pages without sorting', function (): void {
    expect(array_column($this->datasets->from('people')->offset(2)->get(), 'id'))->toBe([3])
        ->and($this->datasets->from('people')->limit(0)->get())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| joining
|--------------------------------------------------------------------------
*/

it('enriches rows through a declared relation', function (): void {
    $rows = $this->datasets->from('orders')
        ->join('crm_customers')
        ->select('orders.id', 'crm_customers.name')
        ->orderBy('orders.id')
        ->get();

    expect($rows)->toBe([
        ['id' => 1, 'name' => 'Ada'],
        ['id' => 2, 'name' => 'Linus'],
        ['id' => 3, 'name' => 'Ada'],
    ]);
});

it('joins on keys named in either order', function (): void {
    $forward = $this->datasets->from('orders')->join('crm_customers', 'orders.customer_id', 'crm_customers.id');
    $reversed = $this->datasets->from('orders')->join('crm_customers', 'crm_customers.id', 'orders.customer_id');

    expect($forward->select('orders.id')->get())->toBe($reversed->select('orders.id')->get())
        ->and($forward->get())->toHaveCount(3);
});

it('drops unmatched rows on an inner join and keeps them on a left join', function (): void {
    $this->datasets->fake('orders', [
        ['id' => 1, 'customer_id' => 'c-1', 'amount' => '10.00', 'created_at' => '2026-01-01'],
        ['id' => 2, 'customer_id' => 'unknown', 'amount' => '20.00', 'created_at' => '2026-01-02'],
    ]);

    $inner = $this->datasets->from('orders')->join('crm_customers')->get();
    $left = $this->datasets->from('orders')
        ->leftJoin('crm_customers')
        ->select('orders.id', 'crm_customers.name')
        ->get();

    expect($inner)->toHaveCount(1)
        ->and($left)->toBe([['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => null]]);
});

it('yields a row per match when the joined side repeats a key', function (): void {
    $this->datasets->fake('tags', [
        ['customer_id' => 'c-1', 'tag' => 'vip'],
        ['customer_id' => 'c-1', 'tag' => 'early'],
    ], Schema::make()->string('customer_id')->string('tag'));

    $rows = $this->datasets->from('crm_customers')
        ->join('tags', 'crm_customers.id', 'tags.customer_id')
        ->select('tag')
        ->get();

    expect(array_column($rows, 'tag'))->toBe(['vip', 'early']);
});

it('qualifies the field names two joined datasets share', function (): void {
    $row = $this->datasets->from('orders')->join('crm_customers')->first();

    expect(array_keys((array) $row))->toBe([
        'orders.id', 'customer_id', 'amount', 'created_at', 'crm_customers.id', 'name', 'country',
    ]);
});

it('refuses a join with no declared relation', function (): void {
    $this->datasets->from('orders')->join('people')->get();
})->throws(IncompatibleJoinKeys::class, 'No relation is declared between [orders] and [people]');

it('refuses join keys whose types could never match', function (): void {
    $this->datasets->from('orders')->join('people', 'orders.customer_id', 'people.id')->get();
})->throws(IncompatibleJoinKeys::class, 'the declared types are not comparable');

it('refuses join keys that do not name the joined dataset', function (): void {
    $this->datasets->from('orders')->join('crm_customers', 'orders.customer_id', 'orders.id')->get();
})->throws(QueryException::class, 'One key must name [crm_customers]');

it('refuses joining the same dataset twice', function (): void {
    $this->datasets->from('orders')->join('orders', 'orders.id', 'orders.id')->get();
})->throws(QueryException::class, 'only once');

/*
|--------------------------------------------------------------------------
| unions
|--------------------------------------------------------------------------
*/

it('appends same shaped datasets to each other', function (): void {
    $rows = $this->datasets->union('shop_a_sales', 'shop_b_sales')->orderBy('sku')->get();

    expect(array_column($rows, 'sku'))->toBe(['A-1', 'A-2', 'B-1']);
});

it('tags each row of a union with the dataset it came from', function (): void {
    $rows = $this->datasets->union('shop_a_sales', 'shop_b_sales')->tagSource('source')->orderBy('sku')->get();

    expect(array_column($rows, 'source'))->toBe(['shop_a_sales', 'shop_a_sales', 'shop_b_sales'])
        ->and(array_keys($rows[0]))->toBe(['source', 'sku', 'units', 'revenue']);
});

it('refuses a union of different shapes', function (): void {
    $this->datasets->union('shop_a_sales', 'people')->get();
})->throws(IncompatibleUnion::class);

it('refuses a union of one', function (): void {
    $this->datasets->union('shop_a_sales')->get();
})->throws(IncompatibleUnion::class, 'at least two');

/*
|--------------------------------------------------------------------------
| grouping and aggregates
|--------------------------------------------------------------------------
*/

it('aggregates across a join', function (): void {
    $rows = $this->datasets->from('orders')
        ->join('crm_customers')
        ->groupBy('crm_customers.name', [
            'revenue' => Aggregate::sum('orders.amount'),
            'orders' => Aggregate::count(),
        ])
        ->orderBy('name')
        ->get();

    expect($rows)->toBe([
        ['name' => 'Ada', 'revenue' => 49.99, 'orders' => 2],
        ['name' => 'Linus', 'revenue' => 5.5, 'orders' => 1],
    ]);
});

it('sums decimals exactly', function (): void {
    $this->datasets->fake('cents', array_fill(0, 10, ['amount' => '0.10']), Schema::make()->decimal('amount', 2));

    $row = $this->datasets->from('cents')->aggregate(['total' => Aggregate::sum('amount')])->first();

    expect($row)->toBe(['total' => 1.0]);
});

it('computes every aggregate', function (): void {
    $row = $this->datasets->from('orders')->aggregate([
        'sum' => Aggregate::sum('amount'),
        'avg' => Aggregate::avg('amount'),
        'min' => Aggregate::min('amount'),
        'max' => Aggregate::max('created_at'),
        'rows' => Aggregate::count(),
        'customers' => Aggregate::countDistinct('customer_id'),
    ])->first();

    expect($row)->toBe([
        'sum' => 55.49,
        'avg' => 18.5,
        'min' => 5.5,
        'max' => '2026-03-05',
        'rows' => 3,
        'customers' => 2,
    ]);
});

it('ignores nulls in aggregates and returns one row over nothing', function (): void {
    $cities = $this->datasets->from('people')->aggregate([
        'cities' => Aggregate::count('city'),
        'first' => Aggregate::min('city'),
    ])->first();

    $empty = $this->datasets->from('orders')->whereIn('id', [])->aggregate([
        'rows' => Aggregate::count(),
        'total' => Aggregate::sum('amount'),
    ])->get();

    expect($cities)->toBe(['cities' => 2, 'first' => 'Berlin'])
        ->and($empty)->toBe([['rows' => 0, 'total' => null]]);
});

it('aggregates over a union', function (): void {
    $row = $this->datasets->union('shop_a_sales', 'shop_b_sales')->aggregate(['units' => Aggregate::sum('units')])->first();

    expect($row)->toBe(['units' => 10]);
});

it('groups by an alias and sorts by the grouped column', function (): void {
    $rows = $this->datasets->from('orders')
        ->join('crm_customers')
        ->groupBy('crm_customers.name as customer', ['orders' => Aggregate::count()])
        ->orderBy('crm_customers.name', 'desc')
        ->get();

    expect($rows)->toBe([['customer' => 'Linus', 'orders' => 1], ['customer' => 'Ada', 'orders' => 2]]);
});

it('filters groups with having', function (): void {
    $rows = $this->datasets->from('orders')
        ->groupBy('customer_id', ['revenue' => Aggregate::sum('amount')])
        ->having('revenue', '>', 10)
        ->get();

    expect($rows)->toBe([['customer_id' => 'c-1', 'revenue' => 49.99]]);
});

it('orders by an aggregate alias', function (): void {
    $rows = $this->datasets->from('orders')
        ->join('crm_customers')
        ->groupBy('crm_customers.name', ['revenue' => Aggregate::sum('orders.amount')])
        ->orderBy('revenue', 'desc')
        ->get();

    expect(array_column($rows, 'name'))->toBe(['Ada', 'Linus']);
});

it('refuses to sum a field that is not a number', function (): void {
    $this->datasets->from('people')->aggregate(['total' => Aggregate::sum('name')])->get();
})->throws(QueryException::class, 'Cannot compute [sum(name)] over a string field');

it('refuses to sort a grouped query by a column it does not output', function (): void {
    $this->datasets->from('orders')->groupBy('customer_id')->orderBy('amount')->get();
})->throws(QueryException::class, 'outputs only [customer_id]');

/*
|--------------------------------------------------------------------------
| checks before anything is fetched
|--------------------------------------------------------------------------
*/

it('refuses a column no dataset declares', function (): void {
    $this->datasets->from('people')->where('age', '>', 1)->get();
})->throws(QueryException::class, 'declares no field named [age]');

it('refuses a column two datasets declare without its dataset', function (): void {
    $this->datasets->from('orders')->join('crm_customers')->select('id')->get();
})->throws(QueryException::class, 'The column [id] is declared by more than one dataset');

it('refuses a dataset that is not in the query', function (): void {
    $this->datasets->from('orders')->select('people.name')->get();
})->throws(QueryException::class, 'The dataset [people] is not part of this query');

it('refuses an unknown dataset', function (): void {
    $this->datasets->from('ordrs')->get();
})->throws(UnknownDataset::class);

it('refuses a malformed column while the query is built', function (string $column): void {
    $this->datasets->from('people')->select($column);
})->with(['upper(name)', 'id + 1', 'name as', 'a.b.c'])->throws(QueryException::class, 'is not a valid column');

it('checks before fetching anything', function (): void {
    $watched = new class extends Dataset
    {
        public static bool $fetched = false;
        public string $name = 'watched';

        public function fetch(Params $params): iterable
        {
            self::$fetched = true;

            return [['id' => 1]];
        }

        public function schema(): Schema
        {
            return Schema::make()->int('id');
        }
    };

    $this->datasets->register($watched);

    expect(fn() => $this->datasets->from('watched')->where('nope', 1)->result())
        ->toThrow(QueryException::class)
        ->and($watched::$fetched)->toBeFalse();
});
