<?php

namespace Aldeebhasan\Panorama\Render;

use Aldeebhasan\Panorama\Query\Result;
use Aldeebhasan\Panorama\Report\Column;
use Generator;

/**
 * Turns a streamed result into a streamed document.
 *
 * Renderers yield chunks rather than returning a string, so a report of any
 * size is written with constant memory whether it ends up in a response or on
 * a disk.
 */
interface RendererContract
{
    /**
     * The name this format is asked for by, e.g. `csv`.
     */
    public function format(): string;

    public function extension(): string;

    public function contentType(): string;

    /**
     * @param list<Column> $columns
     * @return Generator<int,string>
     */
    public function render(Result $result, array $columns): Generator;
}
