<?php

namespace Aldeebhasan\Panorama\Render;

use Aldeebhasan\Panorama\Query\Result;
use Aldeebhasan\Panorama\Report\Column;
use Generator;

/**
 * A JSON array of objects, streamed one row at a time.
 *
 * Cells are the raw reading of each column, not the display one: JSON is read
 * by another system, and `"1,234.56 USD"` is worse than `1234.56` for every
 * consumer that is not a person. The declared columns still decide which
 * fields appear and under which keys.
 */
final class JsonRenderer implements RendererContract
{
    public function __construct(private readonly int $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    {
    }

    public function format(): string
    {
        return 'json';
    }

    public function extension(): string
    {
        return 'json';
    }

    public function contentType(): string
    {
        return 'application/json';
    }

    public function render(Result $result, array $columns, string $title = ''): Generator
    {
        yield '[';

        $first = true;

        foreach ($result->rows() as $row) {
            yield ($first ? '' : ',') . (string) json_encode($this->shape($row, $columns), $this->flags);

            $first = false;
        }

        yield ']';
    }

    /**
     * @param array<string,mixed> $row
     * @param list<Column> $columns
     * @return array<string,mixed>
     */
    private function shape(array $row, array $columns): array
    {
        if ($columns === []) {
            return $row;
        }

        $shaped = [];
        foreach ($columns as $column) {
            $shaped[$column->name] = $column->value($row);
        }

        return $shaped;
    }
}
