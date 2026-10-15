<?php

namespace Aldeebhasan\Panorama\Query;

/**
 * One aggregate a grouped query computes per group, e.g. `sum(orders.amount)`.
 *
 * Nulls are ignored, as in SQL: `count('field')` counts the non-null values,
 * and `sum`, `avg`, `min` and `max` over nothing but nulls are null. Only
 * `count()` with no column counts rows.
 *
 *     ->groupBy('crm_customers.name', [
 *         'revenue' => Aggregate::sum('orders.amount'),
 *         'orders' => Aggregate::count(),
 *     ])
 */
final readonly class Aggregate
{
    private function __construct(
        public string $function,
        public ?string $column = null,
        public bool $distinct = false,
    ) {
    }

    public static function sum(string $column): self
    {
        return new self('sum', $column);
    }

    public static function avg(string $column): self
    {
        return new self('avg', $column);
    }

    public static function min(string $column): self
    {
        return new self('min', $column);
    }

    public static function max(string $column): self
    {
        return new self('max', $column);
    }

    /**
     * Rows in the group with no column, or non-null values of the column.
     */
    public static function count(?string $column = null): self
    {
        return new self('count', $column);
    }

    /**
     * Distinct non-null values of the column in the group.
     */
    public static function countDistinct(string $column): self
    {
        return new self('count', $column, distinct: true);
    }

    public function __toString(): string
    {
        return sprintf('%s(%s%s)', $this->function, $this->distinct ? 'distinct ' : '', $this->column ?? '*');
    }
}
