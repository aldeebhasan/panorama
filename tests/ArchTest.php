<?php

use Aldeebhasan\Panorama\Exceptions\PanoramaException;

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

/*
 * The core stages must stay plain PHP: the Laravel bridge adapts the framework
 * to them, never the other way round. Add each new core namespace here as its
 * milestone lands, so the boundary is enforced rather than just documented.
 */
arch('the core stages do not depend on laravel')
    ->expect([
        'Aldeebhasan\Panorama\Schema',
    ])
    ->not->toUse([
        'Illuminate',
        'app',
        'config',
        'resolve',
        'collect',
        'event',
        'logger',
        'now',
    ]);

arch('every exception the package throws is catchable as one')
    ->expect('Aldeebhasan\Panorama\Exceptions')
    ->toExtend(PanoramaException::class);
