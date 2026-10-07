<?php

namespace Aldeebhasan\Panorama\Facades;

use Aldeebhasan\Panorama\Report\ReportContract;
use Aldeebhasan\Panorama\Report\ReportRun;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Aldeebhasan\Panorama\Panorama register(string|ReportContract $report)
 * @method static ReportContract report(string $key)
 * @method static ReportRun run(string|ReportContract $report, array<string,mixed> $params = [], mixed $user = null)
 * @method static bool has(string $key)
 * @method static list<string> keys()
 * @method static array<string,string> all()
 *
 * @see \Aldeebhasan\Panorama\Panorama
 */
class Panorama extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Aldeebhasan\Panorama\Panorama::class;
    }
}
