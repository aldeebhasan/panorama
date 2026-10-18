<?php

namespace Aldeebhasan\Panorama\Exceptions;

use Aldeebhasan\Panorama\Report\ReportContract;

/**
 * Thrown when a report cannot be resolved or may not be run.
 */
class ReportingException extends PanoramaException
{
    public static function invalidReport(string $report): self
    {
        return new self(sprintf('[%s] must implement [%s].', $report, ReportContract::class));
    }

    /**
     * @param list<string> $known
     */
    public static function reportNotFound(string $key, array $known = []): self
    {
        $message = sprintf('No report registered under the key [%s].', $key);

        if ($known !== []) {
            sort($known);
            $message .= sprintf(' Known reports are [%s].', implode(', ', $known));
        }

        return new self($message);
    }

    public static function unauthorized(string $key): self
    {
        return new self(sprintf('This user is not allowed to run the report [%s].', $key));
    }

    public static function cannotQueue(string $report): self
    {
        return new self(sprintf(
            'The report [%s] cannot be queued: a worker can only rebuild a report it can resolve by key or class. '
            . 'Register it, or give it a named class.',
            $report,
        ));
    }

    public static function queueUnavailable(): self
    {
        return new self('Queued runs need a queue dispatcher. Resolve Panorama from the container to queue a report.');
    }
}
