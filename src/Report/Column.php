<?php

namespace Aldeebhasan\Panorama\Report;

use Closure;
use DateTimeImmutable;
use Exception;
use Stringable;

/**
 * How one column of a report is labelled and presented.
 *
 * A column carries two readings of the same cell: the raw value, for the
 * formats machines consume, and a display string, for the formats people read.
 * That is what lets the same report render correctly as both JSON and CSV
 * without the report saying anything twice.
 */
final class Column
{
    private ?string $label = null;
    private ?Closure $formatter = null;
    private bool $total = false;
    private ?string $currency = null;
    private int $decimals = 0;
    private ?string $dateFormat = null;
    private bool $percent = false;
    private bool $numeric = false;
    private string $empty = '';

    private function __construct(public readonly string $name)
    {
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation
    |--------------------------------------------------------------------------
    */

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * An amount, grouped and suffixed with its currency code.
     */
    public function money(string $currency = 'USD', int $decimals = 2): self
    {
        $this->currency = $currency;
        $this->decimals = $decimals;
        $this->numeric = true;

        return $this;
    }

    public function number(int $decimals = 0): self
    {
        $this->decimals = $decimals;
        $this->numeric = true;

        return $this;
    }

    /**
     * A ratio shown as a percentage. Values are expected as `0.42`, not `42`.
     */
    public function percent(int $decimals = 1): self
    {
        $this->percent = true;
        $this->decimals = $decimals;
        $this->numeric = true;

        return $this;
    }

    public function date(string $format = 'Y-m-d'): self
    {
        $this->dateFormat = $format;

        return $this;
    }

    public function datetime(string $format = 'Y-m-d H:i'): self
    {
        $this->dateFormat = $format;

        return $this;
    }

    /**
     * Format this column yourself. Wins over every other presentation option.
     *
     * The closure receives the raw cell and the whole row, and may return
     * anything that has an honest text form.
     *
     * @param Closure(mixed,array<string,mixed>):mixed $formatter
     */
    public function format(Closure $formatter): self
    {
        $this->formatter = $formatter;

        return $this;
    }

    /**
     * What to show instead of an empty cell.
     */
    public function whenEmpty(string $placeholder): self
    {
        $this->empty = $placeholder;

        return $this;
    }

    /**
     * Sum this column and show the total in a footer.
     */
    public function total(bool $total = true): self
    {
        $this->total = $total;

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * The heading, defaulting to the field name made readable.
     */
    public function heading(): string
    {
        return $this->label ?? ucwords(str_replace('_', ' ', $this->name));
    }

    public function totalled(): bool
    {
        return $this->total;
    }

    public function currency(): ?string
    {
        return $this->currency;
    }

    /**
     * Whether this column was declared as a number, money or a percentage, so
     * a format that lays cells out can align it as one.
     */
    public function isNumeric(): bool
    {
        return $this->numeric;
    }

    /**
     * The raw cell, as the merge produced it.
     *
     * @param array<string,mixed> $row
     */
    public function value(array $row): mixed
    {
        return $row[$this->name] ?? null;
    }

    /**
     * The cell as a person should read it.
     *
     * @param array<string,mixed> $row
     */
    public function display(array $row): string
    {
        $value = $this->value($row);

        if ($this->formatter !== null) {
            return $this->asText(($this->formatter)($value, $row));
        }

        if ($value === null || $value === '') {
            return $this->empty;
        }

        return match (true) {
            $this->dateFormat !== null => $this->asDate($value),
            $this->percent => $this->asNumber($value, 100) . '%',
            $this->currency !== null => $this->asNumber($value) . ' ' . $this->currency,
            $this->decimals > 0 => $this->asNumber($value),
            default => $this->asText($value),
        };
    }

    /**
     * The numeric cell, for the footer total. Null when it is not a number.
     *
     * @param array<string,mixed> $row
     */
    public function numeric(array $row): ?float
    {
        $value = $this->value($row);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * The plainest text form of a cell, whatever it turned out to be.
     */
    private function asText(mixed $value): string
    {
        return match (true) {
            $value === null => $this->empty,
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            $value instanceof Stringable => (string) $value,
            is_array($value) => (string) json_encode($value),
            default => get_debug_type($value),
        };
    }

    private function asNumber(mixed $value, float $scale = 1.0): string
    {
        return is_numeric($value)
            ? number_format((float) $value * $scale, $this->decimals)
            : (is_scalar($value) ? (string) $value : '');
    }

    private function asDate(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) {
            return '';
        }

        try {
            $date = is_int($value)
                ? new DateTimeImmutable('@' . $value)
                : new DateTimeImmutable($value);
        } catch (Exception) {
            // A cell that is not a date is shown as it came, not hidden.
            return $this->asText($value);
        }

        return $date->format((string) $this->dateFormat);
    }
}
