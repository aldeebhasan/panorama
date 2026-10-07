<?php

namespace Aldeebhasan\Panorama\Facades;

use Aldeebhasan\Panorama\Contracts\ReportContract;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Aldeebhasan\Panorama\Panorama register(string|ReportContract $report)
 * @method static ReportContract report(string $key)
 * @method static array<array-key,mixed> generate(string $key, array<string,mixed> $filters = [])
 * @method static bool forget(string $key, array<string,mixed> $filters = [])
 * @method static array<string,string> all()
 * @method static bool has(string $key)
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
