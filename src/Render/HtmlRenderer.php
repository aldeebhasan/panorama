<?php

namespace Aldeebhasan\Panorama\Render;

use Aldeebhasan\Panorama\Query\Result;
use Aldeebhasan\Panorama\Report\Column;
use Generator;

/**
 * A standalone HTML page holding the report as a table, streamed row by row.
 *
 * Cells are the display reading of each column, as in CSV: a page is read by a
 * person. Columns declared as numbers, money or percentages are right aligned,
 * and `total()` columns get a footer.
 *
 * The page carries its own small stylesheet and loads nothing, so it opens the
 * same from a download, a disk or a mail attachment. Pass `document: false`
 * for the bare `<table>`, to embed in a page of your own; pass a stylesheet to
 * replace the built-in one.
 *
 * Every value is escaped. Nothing from a dataset ever reaches the page as
 * markup.
 */
final class HtmlRenderer implements RendererContract
{
    private const STYLESHEET = <<<'CSS'
        :root { color-scheme: light dark; --line: #d0d4da; --head: #f3f4f6; }
        @media (prefers-color-scheme: dark) { :root { --line: #3a3f47; --head: #1f2328; } }
        body { font: 14px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; margin: 2rem; }
        h1 { font-size: 1.25rem; margin: 0 0 1rem; }
        .panorama-table-wrap { overflow-x: auto; }
        table.panorama-report { border-collapse: collapse; min-width: 100%; }
        .panorama-report th, .panorama-report td { padding: .4rem .75rem; border-bottom: 1px solid var(--line); text-align: left; white-space: nowrap; }
        .panorama-report thead th { background: var(--head); position: sticky; top: 0; }
        .panorama-report tfoot td { font-weight: 600; border-top: 2px solid var(--line); border-bottom: 0; }
        .panorama-report .numeric { text-align: right; font-variant-numeric: tabular-nums; }
        .panorama-report .empty { text-align: center; opacity: .6; }
        CSS;

    public function __construct(
        private readonly bool $document = true,
        private readonly ?string $stylesheet = null,
    ) {
    }

    public function format(): string
    {
        return 'html';
    }

    public function extension(): string
    {
        return 'html';
    }

    public function contentType(): string
    {
        return 'text/html; charset=UTF-8';
    }

    public function render(Result $result, array $columns, string $title = ''): Generator
    {
        $columns = $columns === [] ? array_map(Column::make(...), $result->columns()) : $columns;

        if ($this->document) {
            yield $this->head($title);
        }

        yield '<div class="panorama-table-wrap"><table class="panorama-report"><thead><tr>';

        foreach ($columns as $column) {
            yield $this->cell('th', $column, $column->heading(), ' scope="col"');
        }

        yield "</tr></thead>\n<tbody>\n";

        $totals = new Totals($columns);
        $empty = true;

        foreach ($result->rows() as $row) {
            $empty = false;
            $totals->add($row);

            $line = '<tr>';
            foreach ($columns as $column) {
                $line .= $this->cell('td', $column, $column->display($row));
            }

            yield $line . "</tr>\n";
        }

        if ($empty) {
            yield sprintf('<tr><td class="empty" colspan="%d">No rows</td></tr>' . "\n", max(1, count($columns)));
        }

        yield '</tbody>';

        if ($totals->any()) {
            $line = '<tfoot><tr>';
            foreach ($totals->footer() as $index => $text) {
                $line .= $this->cell('td', $columns[$index], $text);
            }

            yield $line . '</tr></tfoot>';
        }

        yield "</table></div>\n";

        if ($this->document) {
            yield "</body>\n</html>\n";
        }
    }

    private function head(string $title): string
    {
        $title = trim($title);

        return '<!doctype html>' . "\n"
            . '<html lang="en">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<title>' . self::escape($title !== '' ? $title : 'Report') . '</title>' . "\n"
            . '<style>' . ($this->stylesheet ?? self::STYLESHEET) . '</style>' . "\n"
            . '</head>' . "\n"
            . '<body>' . "\n"
            . ($title !== '' ? '<h1>' . self::escape($title) . '</h1>' . "\n" : '');
    }

    private function cell(string $tag, Column $column, string $text, string $attributes = ''): string
    {
        $class = $column->isNumeric() ? ' class="numeric"' : '';

        return sprintf('<%1$s%2$s%3$s>%4$s</%1$s>', $tag, $class, $attributes, self::escape($text));
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
