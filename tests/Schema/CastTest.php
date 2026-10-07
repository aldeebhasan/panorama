<?php

use Aldeebhasan\Panorama\Exceptions\CastFailed;
use Aldeebhasan\Panorama\Schema\Casts\BoolCast;
use Aldeebhasan\Panorama\Schema\Casts\DateCast;
use Aldeebhasan\Panorama\Schema\Casts\DateTimeCast;
use Aldeebhasan\Panorama\Schema\Casts\DecimalCast;
use Aldeebhasan\Panorama\Schema\Casts\EnumCast;
use Aldeebhasan\Panorama\Schema\Casts\IntCast;
use Aldeebhasan\Panorama\Schema\Casts\JsonCast;
use Aldeebhasan\Panorama\Schema\Casts\StringCast;
use Aldeebhasan\Panorama\Schema\Field;
use Aldeebhasan\Panorama\Schema\FieldType;

/**
 * A bare field of the given type, so casts can be exercised on their own.
 */
function field(FieldType $type, mixed ...$options): Field
{
    return new Field('f', $type, 'f', ...$options);
}

/*
|--------------------------------------------------------------------------
| string
|--------------------------------------------------------------------------
*/

it('casts to string and trims', function (mixed $raw, string $expected): void {
    expect((new StringCast)->cast($raw, field(FieldType::String)))->toBe($expected);
})->with([
    'plain' => ['abc', 'abc'],
    'padded' => ['  abc  ', 'abc'],
    'int' => [5, '5'],
    'float' => [1.5, '1.5'],
    'stringable' => [new class implements Stringable
    {
        public function __toString(): string
        {
            return ' wrapped ';
        }
    }, 'wrapped'],
]);

it('formats a date object landing on a string field as ISO 8601', function (): void {
    $value = new DateTimeImmutable('2026-01-15 10:00:00', new DateTimeZone('UTC'));

    expect((new StringCast)->cast($value, field(FieldType::String)))->toBe('2026-01-15T10:00:00+00:00');
});

it('rejects values that have no honest string form', function (mixed $raw): void {
    (new StringCast)->cast($raw, field(FieldType::String));
})->with([
    'bool' => [true],
    'array' => [[1, 2]],
])->throws(CastFailed::class, 'is not a valid string');

/*
|--------------------------------------------------------------------------
| int
|--------------------------------------------------------------------------
*/

it('casts whole numbers to int', function (mixed $raw, int $expected): void {
    expect((new IntCast)->cast($raw, field(FieldType::Int)))->toBe($expected);
})->with([
    'int' => [5, 5],
    'negative' => [-5, -5],
    'numeric string' => ['42', 42],
    'padded string' => ['  42  ', 42],
    'integral float' => [5.0, 5],
    'integral float string' => ['5.0', 5],
    'exponent string' => ['1e3', 1000],
    'true' => [true, 1],
    'false' => [false, 0],
]);

it('refuses to truncate a fractional value into an int', function (mixed $raw): void {
    (new IntCast)->cast($raw, field(FieldType::Int));
})->with([
    'float' => [1.5],
    'string' => ['1.5'],
])->throws(CastFailed::class, 'is not a whole number');

it('rejects values that are not ints at all', function (mixed $raw): void {
    (new IntCast)->cast($raw, field(FieldType::Int));
})->with([
    'word' => ['abc'],
    'empty' => [''],
    'array' => [[1]],
])->throws(CastFailed::class, 'is not a valid int');

it('rejects an int beyond the platform range', function (): void {
    (new IntCast)->cast(1.0e30, field(FieldType::Int));
})->throws(CastFailed::class, 'is out of the int range');

it('rejects a non finite int', function (): void {
    (new IntCast)->cast(INF, field(FieldType::Int));
})->throws(CastFailed::class, 'is not a finite int');

/*
|--------------------------------------------------------------------------
| decimal
|--------------------------------------------------------------------------
*/

it('rounds decimals to the declared scale', function (mixed $raw, int $scale, float $expected): void {
    expect((new DecimalCast)->cast($raw, field(FieldType::Decimal, scale: $scale)))->toBe($expected);
})->with([
    'down' => ['10.554', 2, 10.55],
    'up' => ['10.556', 2, 10.56],
    'no scale' => ['10.6', 0, 11.0],
    'three places' => [1.23456, 3, 1.235],
    'int' => [10, 2, 10.0],
    'negative' => ['-3.994', 2, -3.99],
]);

it('rejects values that are not decimals', function (mixed $raw): void {
    (new DecimalCast)->cast($raw, field(FieldType::Decimal));
})->with([
    'word' => ['abc'],
    'empty' => [''],
    'bool' => [true],
])->throws(CastFailed::class, 'is not a valid decimal');

it('rejects a non finite decimal', function (): void {
    (new DecimalCast)->cast(INF, field(FieldType::Decimal));
})->throws(CastFailed::class, 'is not a finite decimal');

/*
|--------------------------------------------------------------------------
| bool
|--------------------------------------------------------------------------
*/

it('casts the spellings sources actually use to bool', function (mixed $raw, bool $expected): void {
    expect((new BoolCast)->cast($raw, field(FieldType::Bool)))->toBe($expected);
})->with([
    'true' => [true, true],
    'false' => [false, false],
    'one' => [1, true],
    'zero' => [0, false],
    'string one' => ['1', true],
    'string zero' => ['0', false],
    'word true' => ['true', true],
    'word false' => ['FALSE', false],
    'yes' => ['Yes', true],
    'no' => ['no', false],
    'on' => ['on', true],
    'off' => ['off', false],
    'padded' => ['  t  ', true],
]);

it('does not fall back to php truthiness for bools', function (mixed $raw): void {
    (new BoolCast)->cast($raw, field(FieldType::Bool));
})->with([
    'other int' => [2],
    'word' => ['maybe'],
    'empty' => [''],
])->throws(CastFailed::class, 'is not a valid bool');

/*
|--------------------------------------------------------------------------
| date and datetime
|--------------------------------------------------------------------------
*/

it('casts to a calendar date', function (mixed $raw, string $expected): void {
    expect((new DateCast)->cast($raw, field(FieldType::Date)))->toBe($expected);
})->with([
    'date only' => ['2026-01-15', '2026-01-15'],
    'drops the time' => ['2026-01-15 23:30:00', '2026-01-15'],
    'object' => [new DateTimeImmutable('2026-01-15 23:30:00'), '2026-01-15'],
    'timestamp' => [1768521600, '2026-01-16'],
]);

it('keeps a date on its own day regardless of offset', function (): void {
    expect((new DateCast)->cast('2026-01-15T23:30:00+00:00', field(FieldType::Date)))->toBe('2026-01-15');
});

it('converts a datetime into the declared output timezone', function (): void {
    $value = (new DateTimeCast)->cast(
        '2026-01-15 10:00:00',
        field(FieldType::Datetime, timezone: 'Europe/Berlin'),
    );

    expect($value)->toBe('2026-01-15 11:00:00');
});

it('reads a naive datetime in the declared source timezone', function (): void {
    $value = (new DateTimeCast)->cast(
        '2026-01-15 10:00:00',
        field(FieldType::Datetime, timezone: 'UTC', sourceTimezone: 'Europe/Berlin'),
    );

    expect($value)->toBe('2026-01-15 09:00:00');
});

it('lets an explicit offset in the raw value win over the source timezone', function (): void {
    $value = (new DateTimeCast)->cast(
        '2026-01-15T10:00:00+02:00',
        field(FieldType::Datetime, timezone: 'UTC', sourceTimezone: 'America/New_York'),
    );

    expect($value)->toBe('2026-01-15 08:00:00');
});

it('defaults a datetime with no declared timezone to utc', function (): void {
    expect((new DateTimeCast)->cast('2026-01-15T10:00:00+02:00', field(FieldType::Datetime)))
        ->toBe('2026-01-15 08:00:00');
});

it('rejects values that are not datetimes', function (mixed $raw): void {
    (new DateTimeCast)->cast($raw, field(FieldType::Datetime));
})->with([
    'word' => ['not a date'],
    'empty' => [''],
    'blank' => ['   '],
    'array' => [[2026]],
])->throws(CastFailed::class, 'is not a valid datetime');

/*
|--------------------------------------------------------------------------
| json
|--------------------------------------------------------------------------
*/

it('decodes json into arrays', function (mixed $raw, array $expected): void {
    expect((new JsonCast)->cast($raw, field(FieldType::Json)))->toBe($expected);
})->with([
    'array passthrough' => [['a' => 1], ['a' => 1]],
    'object string' => ['{"a":1}', ['a' => 1]],
    'list string' => ['[1,2]', [1, 2]],
    'nested object becomes nested array' => ['{"a":{"b":2}}', ['a' => ['b' => 2]]],
]);

it('decodes a std class row value into an array', function (): void {
    $value = json_decode('{"a":{"b":2}}');

    expect((new JsonCast)->cast($value, field(FieldType::Json)))->toBe(['a' => ['b' => 2]]);
});

it('rejects malformed json', function (): void {
    (new JsonCast)->cast('{not json', field(FieldType::Json));
})->throws(CastFailed::class, 'is not valid json');

it('rejects json that decodes to a bare scalar', function (): void {
    (new JsonCast)->cast('5', field(FieldType::Json));
})->throws(CastFailed::class, 'is not a json object or array');

/*
|--------------------------------------------------------------------------
| enum
|--------------------------------------------------------------------------
*/

it('accepts a declared enum value', function (mixed $raw, string $expected): void {
    $field = field(FieldType::Enum, values: ['draft', 'sent']);

    expect((new EnumCast)->cast($raw, $field))->toBe($expected);
})->with([
    'exact' => ['draft', 'draft'],
    'padded' => ['  sent  ', 'sent'],
]);

it('rejects a value outside the declared enum and names the allowed ones', function (): void {
    (new EnumCast)->cast('posted', field(FieldType::Enum, values: ['draft', 'sent']));
})->throws(CastFailed::class, 'is not one of [draft, sent]');
