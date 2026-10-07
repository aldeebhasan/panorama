<?php

namespace Aldeebhasan\Panorama\Deliver;

use Aldeebhasan\Panorama\Render\RenderedReport;

/**
 * Where a rendered report goes.
 *
 * What a destination returns depends on what it is: a response to send back, a
 * path that was written, a message that was queued. The caller knows which one
 * it asked for.
 */
interface DestinationContract
{
    public function name(): string;

    public function deliver(RenderedReport $report): mixed;
}
