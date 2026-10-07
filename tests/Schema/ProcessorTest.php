<?php

use Aldeebhasan\Panorama\Exceptions\SchemaViolation;
use Aldeebhasan\Panorama\Schema\Processor;
use Aldeebhasan\Panorama\Schema\Schema;
use Aldeebhasan\Panorama\Schema\Violation;
use Aldeebhasan\Panorama\Schema\ViolationLog;

/**
 * @param iterable<array-key,mixed> $rows
 * @return array<int,array<string,mixed>>
 */
function run(Schema $schema, iterable $rows, string $dataset = 'orders'): array
{
    return iterator_to_array((new Processor($schema, $dataset))->process($rows));
}

/*
|--------------------------------------------------------------------------
| mapping, defaults and nullability
|--------------------------------------------------------------------------
*/

it('maps raw field names onto the declared ones', function (): void {
    $schema = Schema::make()
        ->string('id', from: 'ext_id')
        ->string('name', from: 'custName');

    expect(run($schema, [['ext_id' => 'c-1', 'custName' => 'Ada']]))
        ->toBe([['id' => 'c-1', 'name' => 'Ada']]);
});

it('drops raw fields the schema does not declare', function (): void {
    $schema = Schema::make()->string('id');

    expect(run($schema, [['id' => 'c-1', 'secret' => 'leaked', 'extra' => 1]]))
        ->toBe([['id' => 'c-1']]);
});

it('keeps the declared field order regardless of the raw order', function (): void {
    $schema = Schema::make()->string('a')->string('b')->string('c');

    expect(array_keys(run($schema, [['c' => '3', 'a' => '1', 'b' => '2']])[0]))->toBe(['a', 'b', 'c']);
});

it('falls back to the default when the raw field is absent', function (): void {
    $schema = Schema::make()->string('country')->default('unknown');

    expect(run($schema, [[]]))->toBe([['country' => 'unknown']]);
});

it('falls back to the default when the raw value is null', function (): void {
    $schema = Schema::make()->int('quantity')->default(1);

    expect(run($schema, [['quantity' => null]]))->toBe([['quantity' => 1]]);
});

it('casts the default like any other value', function (): void {
    $schema = Schema::make()->int('quantity')->default('7');

    expect(run($schema, [[]]))->toBe([['quantity' => 7]]);
});

it('keeps null on a nullable field', function (): void {
    $schema = Schema::make()->string('country')->nullable();

    expect(run($schema, [['country' => null], []]))
        ->toBe([['country' => null], ['country' => null]]);
});

it('normalizes the whole example schema end to end', function (): void {
    $schema = Schema::make()
        ->primaryKey('id')
        ->int('id')
        ->string('customer_id')->references('crm_customers.id')
        ->decimal('amount', 2)->currency('USD')
        ->date('created_at')
        ->bool('paid')
        ->enum('status', ['draft', 'sent'])
        ->json('meta')->nullable()
        ->datetime('synced_at', from: 'syncedAt')->timezone('Europe/Berlin');

    $rows = run($schema, [[
        'id' => '17',
        'customer_id' => '  c-1  ',
        'amount' => '19.994',
        'created_at' => '2026-01-15 23:30:00',
        'paid' => 'yes',
        'status' => 'sent',
        'meta' => '{"source":"api"}',
        'syncedAt' => '2026-01-15 10:00:00',
    ]]);

    expect($rows)->toBe([[
        'id' => 17,
        'customer_id' => 'c-1',
        'amount' => 19.99,
        'created_at' => '2026-01-15',
        'paid' => true,
        'status' => 'sent',
        'meta' => ['source' => 'api'],
        'synced_at' => '2026-01-15 11:00:00',
    ]]);
});

/*
|--------------------------------------------------------------------------
| the fail policy
|--------------------------------------------------------------------------
*/

it('fails by default and names the dataset, field, row and reason', function (): void {
    $schema = Schema::make()->int('id')->decimal('amount');

    run($schema, [
        ['id' => 1, 'amount' => '10.00'],
        ['id' => 2, 'amount' => 'abc'],
    ]);
})->throws(
    SchemaViolation::class,
    'Dataset [orders] field [amount] on row 2: is not a valid decimal, got "abc".',
);

it('fails when a non nullable field is missing', function (): void {
    run(Schema::make()->string('name', from: 'custName'), [[]]);
})->throws(SchemaViolation::class, 'field [name] on row 1: is missing from the raw row under [custName]');

it('fails when a non nullable field is explicitly null', function (): void {
    run(Schema::make()->string('name'), [['name' => null]]);
})->throws(SchemaViolation::class, 'field [name] on row 1: is null but the field is not nullable');

it('carries the violation on the exception', function (): void {
    $schema = Schema::make()->int('id');

    try {
        run($schema, [['id' => 'abc']], 'crm_customers');
    } catch (SchemaViolation $e) {
        expect($e->dataset)->toBe('crm_customers')
            ->and($e->violation->field)->toBe('id')
            ->and($e->violation->row)->toBe(1)
            ->and($e->violation->value)->toBe('abc');

        return;
    }

    $this->fail('no violation was thrown');
});

/*
|--------------------------------------------------------------------------
| the skip policy
|--------------------------------------------------------------------------
*/

it('drops the offending row and counts it when skipping', function (): void {
    $schema = Schema::make()->skipOnViolation()->int('id')->decimal('amount');
    $processor = new Processor($schema, 'orders');

    $rows = iterator_to_array($processor->process([
        ['id' => 1, 'amount' => '10.00'],
        ['id' => 2, 'amount' => 'abc'],
        ['id' => 3, 'amount' => '30.00'],
    ]));

    expect(array_column($rows, 'id'))->toBe([1, 3])
        ->and($processor->log()->skipped())->toBe(1)
        ->and($processor->log()->accepted())->toBe(2)
        ->and($processor->log()->nullified())->toBe(0)
        ->and($processor->log()->clean())->toBeFalse()
        ->and($processor->log()->violations()[0]->row)->toBe(2);
});

it('stops at the first bad field of a skipped row', function (): void {
    $schema = Schema::make()->skipOnViolation()->int('a')->int('b');
    $processor = new Processor($schema, 'orders');

    iterator_to_array($processor->process([['a' => 'x', 'b' => 'y']]));

    expect($processor->log()->skipped())->toBe(1)
        ->and($processor->log()->violations())->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| the nullify policy
|--------------------------------------------------------------------------
*/

it('nulls the offending value and keeps the row when nullifying', function (): void {
    $schema = Schema::make()->nullifyOnViolation()->int('id')->decimal('amount');
    $processor = new Processor($schema, 'orders');

    $rows = iterator_to_array($processor->process([
        ['id' => 1, 'amount' => '10.00'],
        ['id' => 2, 'amount' => 'abc'],
    ]));

    expect($rows)->toBe([
        ['id' => 1, 'amount' => 10.0],
        ['id' => 2, 'amount' => null],
    ])
        ->and($processor->log()->nullified())->toBe(1)
        ->and($processor->log()->accepted())->toBe(2)
        ->and($processor->log()->skipped())->toBe(0);
});

it('nullifies every bad field of the same row', function (): void {
    $schema = Schema::make()->nullifyOnViolation()->int('a')->int('b')->int('c');
    $processor = new Processor($schema, 'orders');

    $rows = iterator_to_array($processor->process([['a' => 'x', 'b' => 2, 'c' => 'z']]));

    expect($rows)->toBe([['a' => null, 'b' => 2, 'c' => null]])
        ->and($processor->log()->nullified())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| streaming and row shapes
|--------------------------------------------------------------------------
*/

it('pulls one raw row at a time', function (): void {
    $pulled = 0;
    $rows = (function () use (&$pulled): Generator {
        foreach ([['id' => 1], ['id' => 2], ['id' => 3]] as $row) {
            $pulled++;

            yield $row;
        }
    })();

    $processed = (new Processor(Schema::make()->int('id')))->process($rows);

    expect($pulled)->toBe(0);

    $processed->current();
    expect($pulled)->toBe(1);

    $processed->next();
    expect($pulled)->toBe(2);
});

it('accepts the row shapes sources hand back', function (mixed $row): void {
    expect(run(Schema::make()->int('id'), [$row]))->toBe([['id' => 7]]);
})->with([
    'array' => [['id' => 7]],
    'std class' => [(object) ['id' => 7]],
    'arrayable' => [new class
    {
        /** @return array<string,int> */
        public function toArray(): array
        {
            return ['id' => 7];
        }
    }],
    'traversable' => [new ArrayIterator(['id' => 7])],
]);

it('maps a headerless row positionally', function (): void {
    $schema = Schema::make()->string('name', from: '0')->int('quantity', from: '1');

    expect(run($schema, [['Ada', '3']]))->toBe([['name' => 'Ada', 'quantity' => 3]]);
});

it('counts nothing before the generator is consumed', function (): void {
    $processor = new Processor(Schema::make()->int('id'));
    $processor->process([['id' => 1]]);

    expect($processor->log()->accepted())->toBe(0)
        ->and($processor->log()->clean())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| the violation log
|--------------------------------------------------------------------------
*/

it('caps the stored violations while still counting all of them', function (): void {
    $log = new ViolationLog(sampleSize: 3);

    for ($i = 1; $i <= 10; $i++) {
        $log->skip(new Violation('amount', $i, 'is not a valid decimal'));
    }

    expect($log->skipped())->toBe(10)
        ->and($log->violations())->toHaveCount(3)
        ->and($log->violations()[0]->row)->toBe(1);
});

it('describes the offending value without dumping it whole', function (): void {
    $violation = new Violation('note', 4, 'is not a valid int', str_repeat('x', 200));

    expect($violation->describe())
        ->toStartWith('field [note] on row 4: is not a valid int, got "xxx')
        ->and(strlen($violation->describe()))->toBeLessThan(120);
});

it('inspects every value shape safely', function (mixed $value, string $expected): void {
    expect((new Violation('f', 1, 'bad', $value))->inspect())->toBe($expected);
})->with([
    'string' => ['abc', '"abc"'],
    'true' => [true, 'true'],
    'false' => [false, 'false'],
    'int' => [7, '7'],
    'array' => [[1, 2, 3], 'array(3)'],
    'null' => [null, 'null'],
    'object' => [new stdClass, 'stdClass'],
]);
