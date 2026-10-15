<?php

namespace Aldeebhasan\Panorama\Query;

use Aldeebhasan\Panorama\Schema\Field;
use Aldeebhasan\Panorama\Schema\FieldType;
use Closure;

/**
 * The running state of one {@see Aggregate} over one group.
 *
 * Decimals are summed exactly: each value is scaled to an integer at the
 * field's declared scale, added, and scaled back once at the end. Adding the
 * floats directly is how `19.99 + 30.00` becomes `49.989999999999995`.
 *
 * @internal
 */
final class Accumulator
{
    private int $count = 0;
    private int|float|null $sum = null;
    private mixed $extreme = null;

    /** @var array<string,true> */
    private array $distinct = [];

    /**
     * @param Closure(array<array-key,mixed>):mixed|null $read null for `count()`, which counts rows
     */
    public function __construct(
        private readonly Aggregate $aggregate,
        private readonly ?Closure $read,
        private readonly ?Field $field,
    ) {
    }

    /**
     * @param array<array-key,mixed> $row
     */
    public function add(array $row): void
    {
        if ($this->read === null) {
            $this->count++;

            return;
        }

        $value = ($this->read)($row);

        if ($value === null) {
            return;
        }

        if ($this->aggregate->distinct) {
            $this->distinct[(string) Compare::key($value)] = true;

            return;
        }

        $this->count++;

        match ($this->aggregate->function) {
            'sum', 'avg' => $this->sum = ($this->sum ?? 0) + $this->scaled($value),
            'min' => $this->extreme = $this->count === 1 || Compare::order($value, $this->extreme) < 0 ? $value : $this->extreme,
            'max' => $this->extreme = $this->count === 1 || Compare::order($value, $this->extreme) > 0 ? $value : $this->extreme,
            default => null,
        };
    }

    public function result(): mixed
    {
        return match ($this->aggregate->function) {
            'count' => $this->aggregate->distinct ? count($this->distinct) : $this->count,
            'sum' => $this->sum === null ? null : $this->unscaled($this->sum),
            'avg' => $this->sum === null ? null : $this->average($this->sum),
            default => $this->extreme,
        };
    }

    private function scaled(mixed $value): int|float
    {
        $number = is_int($value) || is_float($value) ? $value : (is_numeric($value) ? +$value : 0);

        return $this->isDecimal() ? (int) round($number * 10 ** $this->scale()) : $number;
    }

    private function unscaled(int|float $sum): int|float
    {
        return $this->isDecimal() ? round($sum / 10 ** $this->scale(), $this->scale()) : $sum;
    }

    private function average(int|float $sum): float
    {
        $average = $sum / $this->count;

        return $this->isDecimal() ? round($average / 10 ** $this->scale(), $this->scale()) : (float) $average;
    }

    private function isDecimal(): bool
    {
        return $this->field?->type === FieldType::Decimal;
    }

    private function scale(): int
    {
        return $this->field->scale ?? 0;
    }
}
