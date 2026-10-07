<?php

use Aldeebhasan\Panorama\Exceptions\SchemaException;
use Aldeebhasan\Panorama\Schema\FieldType;
use Aldeebhasan\Panorama\Schema\Schema;
use Aldeebhasan\Panorama\Schema\ViolationPolicy;

it('builds the schema from the design sketch as one chain', function (): void {
    $schema = Schema::make()
        ->primaryKey('id')
        ->string('id', from: 'ext_id')
        ->string('name', from: 'custName')
        ->string('country')->nullable()
        ->datetime('created_at', from: 'createdAt')->timezone('UTC');

    expect(array_keys($schema->fields()))->toBe(['id', 'name', 'country', 'created_at'])
        ->and($schema->keys())->toBe(['id'])
        ->and($schema->field('id')->from)->toBe('ext_id')
        ->and($schema->field('country')->nullable)->toBeTrue()
        ->and($schema->field('name')->nullable)->toBeFalse()
        ->and($schema->field('created_at')->timezone)->toBe('UTC');
});

it('applies a modifier to the field declared last, not to the whole schema', function (): void {
    $schema = Schema::make()
        ->string('a')->nullable()
        ->string('b');

    expect($schema->field('a')->nullable)->toBeTrue()
        ->and($schema->field('b')->nullable)->toBeFalse();
});

it('declares every field type with its own defaults', function (): void {
    $schema = Schema::make()
        ->string('s')
        ->int('i')
        ->decimal('d', 3)
        ->bool('b')
        ->date('da')
        ->datetime('dt')
        ->json('j')
        ->enum('e', ['draft', 'sent']);

    expect($schema->field('s')->type)->toBe(FieldType::String)
        ->and($schema->field('i')->type)->toBe(FieldType::Int)
        ->and($schema->field('d')->type)->toBe(FieldType::Decimal)
        ->and($schema->field('d')->scale)->toBe(3)
        ->and($schema->field('b')->type)->toBe(FieldType::Bool)
        ->and($schema->field('da')->type)->toBe(FieldType::Date)
        ->and($schema->field('dt')->type)->toBe(FieldType::Datetime)
        ->and($schema->field('j')->type)->toBe(FieldType::Json)
        ->and($schema->field('e')->type)->toBe(FieldType::Enum)
        ->and($schema->field('e')->values)->toBe(['draft', 'sent']);
});

it('defaults the raw name to the field name', function (): void {
    expect(Schema::make()->string('country')->field('country')->from)->toBe('country');
});

it('reads the raw name from either the argument or the modifier', function (): void {
    $schema = Schema::make()
        ->string('a', from: 'rawA')
        ->string('b')->from('rawB');

    expect($schema->field('a')->from)->toBe('rawA')
        ->and($schema->field('b')->from)->toBe('rawB');
});

it('collects the declared relations keyed by their field', function (): void {
    $schema = Schema::make()
        ->int('id')
        ->string('customer_id')->references('crm_customers.id')
        ->string('vendor_id')->references('vendors.uuid');

    expect($schema->relations())->toHaveKeys(['customer_id', 'vendor_id'])
        ->and((string) $schema->relations()['customer_id'])->toBe('crm_customers.id')
        ->and($schema->relations()['vendor_id']->dataset)->toBe('vendors')
        ->and($schema->relations()['vendor_id']->field)->toBe('uuid');
});

it('defaults the violation policy to fail', function (): void {
    expect(Schema::make()->policy())->toBe(ViolationPolicy::Fail);
});

it('switches the violation policy', function (): void {
    expect(Schema::make()->skipOnViolation()->policy())->toBe(ViolationPolicy::Skip)
        ->and(Schema::make()->nullifyOnViolation()->policy())->toBe(ViolationPolicy::Nullify)
        ->and(Schema::make()->skipOnViolation()->failOnViolation()->policy())->toBe(ViolationPolicy::Fail);
});

it('accepts a composite primary key declared before its fields', function (): void {
    $schema = Schema::make()->primaryKey('shop', 'order_id')->string('shop')->int('order_id');

    $schema->assertValid();

    expect($schema->keys())->toBe(['shop', 'order_id']);
});

it('rejects a primary key that names a field the schema never declares', function (): void {
    Schema::make()->primaryKey('missing')->string('id')->assertValid();
})->throws(SchemaException::class, 'does not declare a field named [missing]');

it('rejects the same field declared twice', function (): void {
    Schema::make()->string('id')->int('id');
})->throws(SchemaException::class, 'declared twice');

it('rejects a lookup of an undeclared field', function (): void {
    Schema::make()->string('id')->field('nope');
})->throws(SchemaException::class, 'does not declare a field named [nope]');

it('rejects a modifier called before any field is declared', function (): void {
    Schema::make()->nullable();
})->throws(SchemaException::class, '[nullable()] must be called right after a field is declared');

it('rejects timezone on a field that is not a datetime', function (): void {
    Schema::make()->date('day')->timezone('UTC');
})->throws(SchemaException::class, '[timezone()] cannot be used on the date field [day], it only applies to datetime fields');

it('rejects currency on a field that holds no amount', function (): void {
    Schema::make()->string('name')->currency('USD');
})->throws(SchemaException::class, 'it only applies to decimal or int fields');

it('rejects an unknown timezone when the schema is declared', function (): void {
    Schema::make()->datetime('at')->timezone('Mars/Olympus');
})->throws(SchemaException::class, '[Mars/Olympus] is not a known timezone identifier');

it('rejects a reference that is not written as dataset.field', function (): void {
    Schema::make()->string('customer_id')->references('crm_customers');
})->throws(SchemaException::class, 'must be written as [dataset.field]');

it('rejects an enum with no allowed values', function (): void {
    Schema::make()->enum('status', []);
})->throws(SchemaException::class, 'must declare at least one allowed value');

it('clamps a negative decimal scale to zero', function (): void {
    expect(Schema::make()->decimal('amount', -2)->field('amount')->scale)->toBe(0);
});
