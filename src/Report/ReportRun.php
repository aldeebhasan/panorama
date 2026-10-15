<?php

namespace Aldeebhasan\Panorama\Report;

use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Deliver\DownloadDestination;
use Aldeebhasan\Panorama\Deliver\StorageDestination;
use Aldeebhasan\Panorama\Exceptions\RenderException;
use Aldeebhasan\Panorama\Query\DatasetQuery;
use Aldeebhasan\Panorama\Query\Result;
use Aldeebhasan\Panorama\Render\RenderedReport;
use Aldeebhasan\Panorama\Render\RendererRegistry;
use Generator;

/**
 * One run of one report: its validated parameters, the query it will execute,
 * and the formats it can be turned into.
 *
 * Nothing has happened yet when a run is handed back. The query executes when
 * the rows are first asked for, which is what lets a caller choose the format
 * and the destination afterwards.
 */
final class ReportRun
{
    private ?Result $result = null;
    private ?string $filename = null;

    public function __construct(
        private readonly ReportContract $report,
        private readonly Params $params,
        private readonly DatasetQuery $query,
        private readonly RendererRegistry $renderers,
        private readonly DownloadDestination $download,
        private readonly StorageDestination $storage,
    ) {
    }

    public function report(): ReportContract
    {
        return $this->report;
    }

    public function params(): Params
    {
        return $this->params;
    }

    public function query(): DatasetQuery
    {
        return $this->query;
    }

    /**
     * The rows, streamed. Executed once per run.
     */
    public function result(): Result
    {
        return $this->result ??= $this->query->result();
    }

    /**
     * @return Generator<int,array<string,mixed>>
     */
    public function rows(): Generator
    {
        yield from $this->result()->rows();
    }

    /**
     * Materialize the rows. For reports small enough to hold at once.
     *
     * @return list<array<string,mixed>>
     */
    public function get(): array
    {
        return $this->result()->toArray();
    }

    /**
     * The report's declared columns, or the ones the query produced.
     *
     * @return list<Column>
     */
    public function columns(): array
    {
        $declared = $this->report->columns();

        return $declared === []
            ? array_map(Column::make(...), $this->result()->columns())
            : $declared;
    }

    /**
     * Name the rendered file, extension included.
     */
    public function named(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Rendering
    |--------------------------------------------------------------------------
    */

    /**
     * @throws RenderException
     */
    public function render(string $format): RenderedReport
    {
        $renderer = $this->renderers->get($format);

        return new RenderedReport(
            $this->filename ?? $this->base() . '.' . $renderer->extension(),
            $renderer->contentType(),
            fn(): Generator => $renderer->render($this->result(), $this->columns()),
            $this->download,
            $this->storage,
        );
    }

    /**
     * @throws RenderException
     */
    public function toCsv(): RenderedReport
    {
        return $this->render('csv');
    }

    /**
     * @throws RenderException
     */
    public function toJson(): RenderedReport
    {
        return $this->render('json');
    }

    private function base(): string
    {
        return $this->report instanceof Report ? $this->report->filename() : $this->report->key();
    }
}
