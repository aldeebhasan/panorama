<?php

namespace Aldeebhasan\Panorama\Render;

use Aldeebhasan\Panorama\Query\Result;
use Aldeebhasan\Panorama\Report\Column;
use Generator;
use RuntimeException;
use ZipArchive;

/**
 * An Excel workbook with one sheet, built with PHP's zip extension.
 *
 * Columns declared as numbers, money or percentages are written as numbers so
 * a spreadsheet can sum them, percentages with Excel's percent format. Every
 * other cell is the display reading, as in CSV. Cells are inline strings, so
 * nothing in a dataset is ever read as a formula.
 *
 * The rows are written to a temporary file as they stream by, zipped, and the
 * zip is yielded in chunks, so memory stays flat whatever the size.
 */
final class XlsxRenderer implements RendererContract
{
    private const CHUNK = 1048576;

    public function format(): string
    {
        return 'xlsx';
    }

    public function extension(): string
    {
        return 'xlsx';
    }

    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function render(Result $result, array $columns, string $title = ''): Generator
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Rendering XLSX needs PHP\'s zip extension, ext-zip.');
        }

        $columns = $columns === [] ? array_map(Column::make(...), $result->columns()) : $columns;
        $sheet = self::temporary();
        $zip = self::temporary();

        try {
            $this->writeSheet($sheet, $result, $columns);
            $this->writeWorkbook($zip, $sheet, $title);

            $handle = fopen($zip, 'rb') ?: throw new RuntimeException('Could not read the XLSX back.');

            try {
                while (!feof($handle)) {
                    $chunk = fread($handle, self::CHUNK);

                    if ($chunk !== false && $chunk !== '') {
                        yield $chunk;
                    }
                }
            } finally {
                fclose($handle);
            }
        } finally {
            @unlink($sheet);
            @unlink($zip);
        }
    }

    /**
     * @param list<Column> $columns
     */
    private function writeSheet(string $path, Result $result, array $columns): void
    {
        $handle = fopen($path, 'wb') ?: throw new RuntimeException('Could not write the XLSX sheet.');

        try {
            fwrite($handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');

            $line = 1;
            fwrite($handle, self::row($line++, array_map(fn(Column $column): string => self::text($column->heading()), $columns)));

            $totals = new Totals($columns);

            foreach ($result->rows() as $row) {
                $totals->add($row);

                fwrite($handle, self::row($line++, array_map(fn(Column $column): string => self::cell($column, $row), $columns)));
            }

            if ($totals->any()) {
                fwrite($handle, self::row($line, array_map(self::text(...), $totals->footer())));
            }

            fwrite($handle, '</sheetData></worksheet>');
        } finally {
            fclose($handle);
        }
    }

    private function writeWorkbook(string $path, string $sheet, string $title): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the XLSX archive.');
        }

        // Excel limits a sheet name to 31 characters, without []:*?/\.
        $name = mb_substr(trim((string) preg_replace('/[\[\]:*?\/\\\\]/', '', $title)), 0, 31);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::escape($name !== '' ? $name : 'Report') . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        // Style 1 is Excel's built-in percent format, 0.00%.
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="10" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>');
        $zip->addFile($sheet, 'xl/worksheets/sheet1.xml');

        if (!$zip->close()) {
            throw new RuntimeException('Could not write the XLSX archive.');
        }
    }

    /**
     * @param list<string> $cells
     */
    private static function row(int $line, array $cells): string
    {
        return '<row r="' . $line . '">' . implode('', $cells) . "</row>\n";
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function cell(Column $column, array $row): string
    {
        $number = $column->isNumeric() ? $column->numeric($row) : null;

        if ($number === null) {
            return self::text($column->display($row));
        }

        return sprintf('<c%s><v>%s</v></c>', $column->isPercent() ? ' s="1"' : '', var_export($number, true));
    }

    private static function text(string $text): string
    {
        return $text === '' ? '<c/>' : '<c t="inlineStr"><is><t xml:space="preserve">' . self::escape($text) . '</t></is></c>';
    }

    private static function escape(string $text): string
    {
        // Control characters are not allowed in XML and make Excel refuse the file.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }

    private static function temporary(): string
    {
        return tempnam(sys_get_temp_dir(), 'panorama-xlsx-') ?: throw new RuntimeException('Could not create a temporary file for the XLSX.');
    }
}
