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

    public function render(Result $result, array $columns, string $title = ''): Generator
    {
        if ($this->bom) {
            // Excel needs this to read UTF-8 correctly.
            yield "\xEF\xBB\xBF";
        }

        $columns = $columns === [] ? $this->fromResult($result) : $columns;

        yield $this->line(array_map(fn(Column $column): string => $column->heading(), $columns));

        $totals = new Totals($columns);

        foreach ($result->rows() as $row) {
            $totals->add($row);

            yield $this->line(array_map(fn(Column $column): string => $column->display($row), $columns));
        }

        if ($totals->any()) {
            yield $this->line($totals->footer());
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
