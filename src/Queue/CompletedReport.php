<?php

namespace Aldeebhasan\Panorama\Queue;

/**
 * A queued report that has been rendered and stored: what a notification
 * needs to tell someone it is ready, or to attach it.
 */
final class CompletedReport
{
    /**
     * @param array<string,mixed> $params
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $format,
        public readonly string $filename,
        public readonly string $contentType,
        public readonly ?string $disk,
        public readonly string $path,
        public readonly int $rows,
        public readonly int $bytes,
        public readonly array $params = [],
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'report' => $this->key,
            'name' => $this->name,
            'format' => $this->format,
            'filename' => $this->filename,
            'content_type' => $this->contentType,
            'disk' => $this->disk,
            'path' => $this->path,
            'rows' => $this->rows,
            'bytes' => $this->bytes,
            'params' => $this->params,
        ];
    }
}
