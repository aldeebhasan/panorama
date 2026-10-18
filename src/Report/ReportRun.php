<?php

namespace Aldeebhasan\Panorama\Report;

use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Deliver\DownloadDestination;
use Aldeebhasan\Panorama\Deliver\StorageDestination;
use Aldeebhasan\Panorama\Exceptions\RenderException;
use Aldeebhasan\Panorama\History\NullRecorder;
use Aldeebhasan\Panorama\History\RecorderContract;
use Aldeebhasan\Panorama\History\Recording;
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
        private readonly RecorderContract $recorder = new NullRecorder,
        private readonly ?string $user = null,
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
     * The identifier of the user the run was authorized for, if any.
     */
    public function user(): ?string
    {
        return $this->user;
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
        $filename = $this->filename ?? $this->base() . '.' . $renderer->extension();

        return new RenderedReport(
            $filename,
            $renderer->contentType(),
            fn(): Generator => $renderer->render($this->result(), $this->columns(), $this->report->name()),
            $this->download,
            $this->storage,
            new Recording(
                $this->recorder,
                $this->report->key(),
                $this->report->name(),
                $this->params->all(),
                $renderer->format(),
                $filename,
                $this->user,
                fn(): int => $this->result()->yielded(),
            ),
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

    /**
     * @throws RenderException
     */
    public function toHtml(): RenderedReport
    {
        return $this->render('html');
    }

    private function base(): string
    {
        return $this->report instanceof Report ? $this->report->filename() : $this->report->key();
    }
}
