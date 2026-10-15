<?php

namespace Aldeebhasan\Panorama\Query;

use Closure;

/**
 * How values are compared, matched and ordered while a query runs.
 *
 * The rules follow SQL, so a report reads the way its author expects: a null
 * on either side of a comparison matches nothing, `not in` a list holding a
 * null matches nothing, `like` is case sensitive and `ilike` is not. Where SQL
 * engines disagree this follows SQLite and MySQL: nulls sort first ascending
 * and last descending.
 */
final class Compare
{
    /**
     * Total order over the values a row can hold. Nulls sort first.
     */
    public static function order(mixed $a, mixed $b): int
    {
        if ($a === null || $b === null) {
            return ($b === null) <=> ($a === null);
        }

        return $a <=> $b;
    }

    /**
     * The test a condition applies to one value.
     *
     * @return Closure(mixed):bool
     */
    public static function predicate(Condition $condition): Closure
    {
        $values = $condition->values;

        return match ($condition->kind) {
            'comparison' => self::comparison($condition->operator, $values[0] ?? null),
            'in' => static fn(mixed $value): bool => $value !== null && self::contains($values, $value),
            // A null in the list makes `not in` unknown for every value.
            'notIn' => static fn(mixed $value): bool => $value !== null
                && !in_array(null, $values, true)
                && !self::contains($values, $value),
            'between' => static function (mixed $value) use ($values): bool {
                [$from, $to] = [$values[0] ?? null, $values[1] ?? null];

                return $value !== null && $from !== null && $to !== null
                    && self::order($value, $from) >= 0 && self::order($value, $to) <= 0;
            },
            'null' => static fn(mixed $value): bool => $value === null,
            default => static fn(mixed $value): bool => $value !== null,
        };
    }

    /**
     * A value usable as an array key that two comparable values share, so a
     * join can match them with a hash lookup. Null never matches anything.
     */
    public static function key(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            is_float($value) && floor($value) === $value => (string) (int) $value,
            is_scalar($value) => (string) $value,
            default => serialize($value),
        };
    }

    /**
     * @return Closure(mixed):bool
     */
    private static function comparison(string $operator, mixed $operand): Closure
    {
        if (in_array($operator, ['like', 'not like', 'ilike'], true)) {
            $pattern = self::likePattern(is_scalar($operand) ? (string) $operand : '', $operator === 'ilike');
            $negate = $operator === 'not like';

            return static function (mixed $value) use ($operand, $pattern, $negate): bool {
                if ($value === null || $operand === null || !is_scalar($value)) {
                    return false;
                }

                return (preg_match($pattern, (string) $value) === 1) !== $negate;
            };
        }

        return static function (mixed $value) use ($operator, $operand): bool {
            if ($value === null || $operand === null) {
                return false;
            }

            $compared = self::order($value, $operand);

            return match ($operator) {
                '=' => $compared === 0,
                '!=', '<>' => $compared !== 0,
                '<' => $compared < 0,
                '<=' => $compared <= 0,
                '>' => $compared > 0,
                default => $compared >= 0,
            };
        };
    }

    /**
     * @param list<mixed> $values
     */
    private static function contains(array $values, mixed $value): bool
    {
        foreach ($values as $candidate) {
            if ($candidate !== null && self::order($value, $candidate) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * `%` is any run of characters and `_` exactly one; everything else is
     * literal.
     */
    private static function likePattern(string $like, bool $insensitive): string
    {
        $pattern = strtr(preg_quote($like, '/'), ['%' => '.*', '_' => '.']);

        return '/^' . $pattern . '$/s' . ($insensitive ? 'i' : '');
    }
}
