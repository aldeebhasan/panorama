<?php

namespace Aldeebhasan\Panorama\Render;

use Aldeebhasan\Panorama\Query\Result;
use Aldeebhasan\Panorama\Report\Column;
use Generator;
use RuntimeException;

/**
 * Comma separated values, one row at a time.
 *
 * Cells are the display reading of each column: a CSV is opened by a person in
 * a spreadsheet, so money, dates and percentages are formatted. Use JSON when
 * another system is the consumer.
 *
 * Escaping is `fputcsv`'s, which is RFC 4180. The design proposed
 * `league/csv`; it is not a dependency because the standard function covers
 * what the MVP needs, and this whole class is one interface away from being
 * swapped for it.
 */
final class CsvRenderer implements RendererContract
{
    public function __construct(
        private readonly string $separator = ',',
        private readonly bool $bom = false,
    ) {
    }

    public function format(): string
    {
        return 'csv';
    }

    public function extension(): string
    {
        return 'csv';
    }

    public function contentType(): string
    {
        return 'text/csv';
    }

    public function render(Result $result, array $columns): Generator
    {
        if ($this->bom) {
            // Excel needs this to read UTF-8 correctly.
            yield "\xEF\xBB\xBF";
        }

        $columns = $columns === [] ? $this->fromResult($result) : $columns;

        yield $this->line(array_map(fn(Column $column): string => $column->heading(), $columns));

        $totals = [];

        foreach ($result->rows() as $row) {
            foreach ($columns as $index => $column) {
                if ($column->totalled()) {
                    $totals[$index] = ($totals[$index] ?? 0.0) + ($column->numeric($row) ?? 0.0);
                }
            }

            yield $this->line(array_map(fn(Column $column): string => $column->display($row), $columns));
        }

        if ($totals !== []) {
            yield $this->line($this->footer($columns, $totals));
        }
    }

    /**
     * A report that declared no columns is rendered under the names the query
     * produced.
     *
     * @return list<Column>
     */
    private function fromResult(Result $result): array
    {
        return array_map(Column::make(...), $result->columns());
    }

    /**
     * @param list<Column> $columns
     * @param array<int,float> $totals
     * @return list<string>
     */
    private function footer(array $columns, array $totals): array
    {
        $footer = [];

        foreach ($columns as $index => $column) {
            $footer[] = isset($totals[$index])
                ? $column->display([$column->name => $totals[$index]])
                : ($index === 0 ? 'Total' : '');
        }

        return $footer;
    }

    /**
     * @param list<string> $cells
     */
    private function line(array $cells): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary stream to write the CSV.');
        }

        fputcsv($handle, $cells, $this->separator);
        rewind($handle);
        $line = stream_get_contents($handle);
        fclose($handle);

        return $line === false ? '' : $line;
    }
}
