<?php

namespace Aldeebhasan\Panorama\Deliver;

use Aldeebhasan\Panorama\Exceptions\RenderException;
use Aldeebhasan\Panorama\Render\RenderedReport;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * Writes the report to a Storage disk and returns the path it took.
 *
 * The chunks are handed to the disk as a stream rather than a string, so an
 * upload to object storage never holds the whole report in memory.
 */
final class StorageDestination implements DestinationContract
{
    public function __construct(
        private readonly FilesystemFactory $disks,
        private readonly ?string $disk = null,
        private readonly string $directory = 'reports',
    ) {
    }

    public function name(): string
    {
        return 'storage';
    }

    public function on(?string $disk, ?string $directory = null): self
    {
        return new self($this->disks, $disk ?? $this->disk, $directory ?? $this->directory);
    }

    /**
     * @throws RenderException
     */
    public function deliver(RenderedReport $report): string
    {
        $path = trim($this->directory, '/') === ''
            ? $report->filename()
            : trim($this->directory, '/') . '/' . $report->filename();

        $stream = $report->stream();

        try {
            $written = $this->disks->disk($this->disk)->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false) {
            throw RenderException::couldNotStore($this->disk ?? 'default', $path);
        }

        return $path;
    }
}
