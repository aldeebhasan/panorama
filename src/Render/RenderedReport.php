<?php

namespace Aldeebhasan\Panorama\Render;

use Aldeebhasan\Panorama\Deliver\DestinationContract;
use Aldeebhasan\Panorama\Deliver\DownloadDestination;
use Aldeebhasan\Panorama\Deliver\StorageDestination;
use Aldeebhasan\Panorama\History\Recording;
use Closure;
use Generator;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * A report rendered into a named document, not yet delivered anywhere.
 *
 * The document is a stream of chunks that is produced on demand, so nothing is
 * generated until a destination asks for it, and it is only ever walked once.
 *
 * When history is kept, the run is recorded as started when delivery begins
 * and settled when the last chunk is out, or, for a destination that writes the
 * document somewhere, once the write is done. A download is settled when its
 * stream ends, which is after the response has been handed back.
 */
final class RenderedReport
{
    private bool $taken = false;
    private bool $produced = false;
    private bool $delivering = false;

    /**
     * @param Closure():Generator<int,string> $producer yields the document a chunk at a time
     */
    public function __construct(
        private readonly string $filename,
        private readonly string $contentType,
        private readonly Closure $producer,
        private readonly DownloadDestination $download,
        private readonly StorageDestination $storage,
        private readonly ?Recording $recording = null,
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
        $this->recording?->start();

        try {
            foreach (($this->producer)() as $chunk) {
                $this->recording?->wrote(strlen($chunk));

                yield $chunk;
            }

            $this->produced = true;
        } catch (Throwable $failure) {
            $this->recording?->fail($failure);

            throw $failure;
        } finally {
            if (!$this->produced && $this->recording?->isSettled() === false) {
                $this->recording->fail('The report was abandoned before it was fully produced.');
            }
        }

        // Read directly rather than through a destination: there is nothing
        // left to wait for. A destination settles the run itself.
        if (!$this->delivering) {
            $this->recording?->succeed();
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
        return $this->deliver($this->download, fn(): StreamedResponse => $this->download->deliver($this));
    }

    /**
     * Write the report to a disk and return the path it took.
     */
    public function store(?string $disk = null, ?string $directory = null): string
    {
        $storage = $this->storage->on($disk, $directory);

        return $this->deliver($storage, fn(): string => $storage->deliver($this));
    }

    public function to(DestinationContract $destination): mixed
    {
        return $this->deliver($destination, fn(): mixed => $destination->deliver($this));
    }

    /**
     * Hand the report to a destination, keeping its history.
     *
     * A destination that consumes the chunks before it returns has delivered
     * the report by then, and what it returned, when a string, is the path it
     * wrote. One that returns first, a streamed response, has not: the run is
     * settled when its stream ends.
     *
     * @template T
     *
     * @param Closure():T $delivery
     * @return T
     */
    private function deliver(DestinationContract $destination, Closure $delivery): mixed
    {
        $this->recording?->via($destination->name());
        $this->recording?->start();

        $this->delivering = true;

        try {
            $delivered = $delivery();
        } catch (Throwable $failure) {
            $this->recording?->fail($failure);

            throw $failure;
        } finally {
            $this->delivering = false;
        }

        if ($this->produced) {
            $this->recording?->succeed(is_string($delivered) ? $delivered : null);
        }

        return $delivered;
    }
}
