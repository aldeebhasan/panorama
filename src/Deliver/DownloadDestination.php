<?php

namespace Aldeebhasan\Panorama\Deliver;

use Aldeebhasan\Panorama\Render\RenderedReport;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the report straight back to the browser.
 *
 * Chunks are flushed as they are produced, so the download starts before the
 * report has finished being generated and memory stays flat however large it
 * turns out to be.
 */
final class DownloadDestination implements DestinationContract
{
    public function name(): string
    {
        return 'download';
    }

    public function deliver(RenderedReport $report): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($report): void {
            foreach ($report->chunks() as $chunk) {
                echo $chunk;

                flush();
            }
        });

        $response->headers->set('Content-Type', $report->contentType());
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="%s"', str_replace('"', '', $report->filename())),
        );

        return $response;
    }
}
