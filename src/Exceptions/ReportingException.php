<?php

namespace Aldeebhasan\Panorama\Exceptions;

use Aldeebhasan\Panorama\Contracts\ReportContract;

class ReportingException extends PanoramaException
{
    public static function invalidReport(string $report): self
    {
        return new self(sprintf('[%s] must implement [%s].', $report, ReportContract::class));
    }

    public static function reportNotFound(string $key): self
    {
        return new self(sprintf('No report registered under the key [%s].', $key));
    }
}
