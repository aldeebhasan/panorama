<?php

use Aldeebhasan\Panorama\Report\Column;

it('makes a heading out of the field name', function (string $name, string $heading): void {
    expect(Column::make($name)->heading())->toBe($heading);
})->with([
    'snake case' => ['customer_name', 'Customer Name'],
    'single word' => ['revenue', 'Revenue'],
    'already spaced' => ['Total Due', 'Total Due'],
]);

it('prefers a declared label', function (): void {
    expect(Column::make('name')->label('Customer')->heading())->toBe('Customer');
});

it('reads the raw cell untouched', function (): void {
    expect(Column::make('revenue')->money('USD')->value(['revenue' => 49.99]))->toBe(49.99)
        ->and(Column::make('missing')->value([]))->toBeNull();
});

it('formats money with its currency', function (): void {
    expect(Column::make('revenue')->money('EUR')->display(['revenue' => '1234.5']))->toBe('1,234.50 EUR')
        ->and(Column::make('revenue')->money('USD', 0)->display(['revenue' => 1234.5]))->toBe('1,235 USD');
});

it('formats a ratio as a percentage', function (): void {
    expect(Column::make('rate')->percent()->display(['rate' => 0.4237]))->toBe('42.4%')
        ->and(Column::make('rate')->percent(0)->display(['rate' => 0.5]))->toBe('50%');
});

it('formats numbers with the declared precision', function (): void {
    expect(Column::make('n')->number(2)->display(['n' => '3.14159']))->toBe('3.14');
});

it('formats dates and datetimes', function (): void {
    expect(Column::make('day')->date('d/m/Y')->display(['day' => '2026-01-15']))->toBe('15/01/2026')
        ->and(Column::make('at')->datetime()->display(['at' => '2026-01-15 10:30:00']))->toBe('2026-01-15 10:30')
        ->and(Column::make('at')->date()->display(['at' => 1768521600]))->toBe('2026-01-16');
});

it('shows a value that is not a date as it came', function (): void {
    expect(Column::make('day')->date()->display(['day' => 'whenever']))->toBe('whenever');
});

it('shows a placeholder for an empty cell', function (): void {
    expect(Column::make('country')->whenEmpty('—')->display(['country' => null]))->toBe('—')
        ->and(Column::make('country')->whenEmpty('—')->display(['country' => '']))->toBe('—')
        ->and(Column::make('country')->display(['country' => null]))->toBe('');
});

it('lets a closure format the cell, with the whole row', function (): void {
    $column = Column::make('name')->format(fn($value, $row) => strtoupper((string) $value) . ' (' . $row['id'] . ')');

    expect($column->display(['name' => 'ada', 'id' => 7]))->toBe('ADA (7)');
});

it('lets a closure win over every other option', function (): void {
    $column = Column::make('revenue')->money('USD')->format(fn($value) => 'about ' . round((float) $value));

    expect($column->display(['revenue' => 49.99]))->toBe('about 50');
});

it('renders whatever shape a cell turns out to be', function (mixed $value, string $display): void {
    expect(Column::make('x')->display(['x' => $value]))->toBe($display);
})->with([
    'string' => ['hello', 'hello'],
    'int' => [7, '7'],
    'float' => [1.5, '1.5'],
    'true' => [true, 'true'],
    'false' => [false, 'false'],
    'array' => [['a' => 1], '{"a":1}'],
]);

it('reads the numeric cell for a total, and nothing else', function (): void {
    expect(Column::make('n')->numeric(['n' => '19.99']))->toBe(19.99)
        ->and(Column::make('n')->numeric(['n' => 'abc']))->toBeNull()
        ->and(Column::make('n')->numeric([]))->toBeNull();
});

it('reports whether it is totalled and what currency it carries', function (): void {
    $column = Column::make('revenue')->money('GBP')->total();

    expect($column->totalled())->toBeTrue()
        ->and($column->currency())->toBe('GBP')
        ->and(Column::make('x')->totalled())->toBeFalse()
        ->and(Column::make('x')->currency())->toBeNull();
});
