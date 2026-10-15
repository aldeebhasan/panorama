<?php

namespace Aldeebhasan\Panorama\Facades;

use Aldeebhasan\Panorama\Dataset\Dataset;
use Aldeebhasan\Panorama\Dataset\DatasetRegistry;
use Aldeebhasan\Panorama\Query\DatasetQuery;
use Aldeebhasan\Panorama\Schema\Schema;
use Illuminate\Support\Facades\Facade;

/**
 * @method static DatasetQuery from(string $dataset)
 * @method static DatasetQuery union(string ...$datasets)
 * @method static Dataset get(string $name)
 * @method static bool has(string $name)
 * @method static list<string> names()
 * @method static array<string,Dataset> all()
 * @method static \Aldeebhasan\Panorama\Dataset\Datasets register(string|Dataset $dataset)
 * @method static \Aldeebhasan\Panorama\Dataset\Datasets fake(string $name, iterable<array-key,mixed> $rows, ?Schema $schema = null)
 * @method static DatasetRegistry registry()
 *
 * @see \Aldeebhasan\Panorama\Dataset\Datasets
 */
class Datasets extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Aldeebhasan\Panorama\Dataset\Datasets::class;
    }
}
