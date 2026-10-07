<?php

namespace Aldeebhasan\Panorama\Query;

/**
 * One filter in a where or having clause.
 *
 * Values are always carried separately from the SQL so they can be bound, and
 * the operator is checked against a whitelist when the condition is built.
 */
final readonly class Condition
{
    public const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'like', 'not like', 'ilike'];

    /**
     * @param list<mixed> $values
     */
    private function __construct(
        public string $kind,
        public string $column = '',
        public string $operator = '=',
        public array $values = [],
        public string $sql = '',
    ) {
    }

    public static function comparison(string $column, string $operator, mixed $value): self
    {
        return new self('comparison', $column, $operator, [$value]);
    }

    /**
     * @param list<mixed> $values
     */
    public static function in(string $column, array $values, bool $not = false): self
    {
        return new self($not ? 'notIn' : 'in', $column, values: array_values($values));
    }

    public static function between(string $column, mixed $from, mixed $to): self
    {
        return new self('between', $column, values: [$from, $to]);
    }

    public static function null(string $column, bool $not = false): self
    {
        return new self($not ? 'notNull' : 'null', $column);
    }

    /**
     * @param list<mixed> $bindings
     */
    public static function raw(string $sql, array $bindings = []): self
    {
        return new self('raw', values: array_values($bindings), sql: $sql);
    }
}
