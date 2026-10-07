<?php

namespace Aldeebhasan\Panorama\Report;

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Query\Merge;

/**
 * A report: the parameters it accepts, the query it runs over datasets, and
 * how its columns are presented.
 *
 * Note what is not here. A report does not say how it is rendered or where it
 * goes, because those are chosen at run time, and it does not return rows,
 * because rows are streamed rather than built.
 */
interface ReportContract
{
    /**
     * The key reports are resolved and run by.
     */
    public function key(): string;

    /**
     * The human readable name.
     */
    public function name(): string;

    /**
     * The filters this report accepts, as validation rules.
     *
     * @return array<string,mixed>
     */
    public function parameters(): array;

    /**
     * The merge this report is.
     */
    public function query(Datasets $datasets): Merge;

    /**
     * How the columns are labelled and formatted. An empty list renders
     * whatever columns the query produced, under their own names.
     *
     * @return list<Column>
     */
    public function columns(): array;

    /**
     * Whether the given user may run this report.
     */
    public function authorize(mixed $user = null): bool;
}
