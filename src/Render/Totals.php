<?php

namespace Aldeebhasan\Panorama\Render;

use Aldeebhasan\Panorama\Report\Column;

/**
 * The running sums behind a `total()` footer, kept while the rows stream by.
 *
 * @internal
 */
final class Totals
{
    /** @var array<int,float> column index => sum */
    private array $sums = [];

    /**
     * @param list<Column> $columns
     */
    public function __construct(private readonly array $columns)
    {
    }

    /**
     * @param array<string,mixed> $row
     */
    public function add(array $row): void
    {
        foreach ($this->columns as $index => $column) {
            if ($column->totalled()) {
                $this->sums[$index] = ($this->sums[$index] ?? 0.0) + ($column->numeric($row) ?? 0.0);
            }
        }
    }

    /**
     * Whether any column is totalled, and so whether there is a footer.
     */
    public function any(): bool
    {
        foreach ($this->columns as $column) {
            if ($column->totalled()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The footer cells, displayed the way each column displays its values.
     * The first cell says `Total` when it is not itself a total.
     *
     * @return list<string>
     */
    public function footer(): array
    {
        $footer = [];

        foreach ($this->columns as $index => $column) {
            $footer[] = $column->totalled()
                ? $column->display([$column->name => $this->sums[$index] ?? 0.0])
                : ($index === 0 ? 'Total' : '');
        }

        return $footer;
    }
}
