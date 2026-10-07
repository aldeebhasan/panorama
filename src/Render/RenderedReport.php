<?php

namespace Aldeebhasan\Panorama\Render;

use Aldeebhasan\Panorama\Deliver\DestinationContract;
use Aldeebhasan\Panorama\Deliver\DownloadDestination;
use Aldeebhasan\Panorama\Deliver\StorageDestination;
use Closure;
use Generator;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A report rendered into a named document, not yet delivered anywhere.
 *
 * The document is a stream of chunks that is produced on demand, so nothing is
 * generated until a destination asks for it, and it is only ever walked once.
 */
final class RenderedReport
{
    private bool $taken = false;

    /**
     * @param Closure():Generator<int,string> $producer yields the document a chunk at a time
     */
    public function __construct(
        private readonly string $filename,
        private readonly string $contentType,
        private readonly Closure $producer,
        private readonly DownloadDestination $download,
        private readonly StorageDestination $storage,
    ) {
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function contentType(): string
    {
        return $this->contentType;
    }

    /**
     * The document, a chunk at a time.
     *
     * @return Generator<int,string>
     */
    public function chunks(): Generator
    {
        if ($this->taken) {
            throw new RuntimeException(sprintf(
                'The report [%s] has already been delivered. Render it again to deliver it twice.',
                $this->filename,
            ));
        }

        $this->taken = true;

        foreach (($this->producer)() as $chunk) {
            yield $chunk;
        }
    }

    /**
     * The document as a readable resource, for consumers that want a stream.
     *
     * Backed by `php://temp`, which keeps small reports in memory and spills
     * larger ones to a temporary file rather than growing without bound.
     *
     * @return resource
     */
    public function stream()
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary stream for the report.');
        }

        foreach ($this->chunks() as $chunk) {
            fwrite($handle, $chunk);
        }

        rewind($handle);

        return $handle;
    }

    /**
     * The whole document as a string. Only for reports small enough to hold.
     */
    public function contents(): string
    {
        $contents = '';

        foreach ($this->chunks() as $chunk) {
            $contents .= $chunk;
        }

        return $contents;
    }

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    */

    public function download(): StreamedResponse
    {
        return $this->download->deliver($this);
    }

    /**
     * Write the report to a disk and return the path it took.
     */
    public function store(?string $disk = null, ?string $directory = null): string
    {
        return $this->storage->on($disk, $directory)->deliver($this);
    }

    public function to(DestinationContract $destination): mixed
    {
        return $destination->deliver($this);
    }
}
