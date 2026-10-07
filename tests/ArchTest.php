<?php

use Aldeebhasan\Panorama\Exceptions\PanoramaException;

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

/*
 * The boundary the MVP actually enforces: core code may take Laravel
 * components as injected dependencies, but never reach for a facade, a global
 * helper or the container. That is what keeps the pipeline constructible and
 * testable without booting an application, and it is the property that has to
 * hold before the core could ever be split into its own package.
 *
 * Add each new core namespace here as its milestone lands.
 */
arch('the core stages never reach for the framework')
    ->expect([
        'Aldeebhasan\Panorama\Schema',
        'Aldeebhasan\Panorama\Connection',
        'Aldeebhasan\Panorama\Dataset',
        'Aldeebhasan\Panorama\Support',
    ])
    ->not->toUse([
        'Illuminate\Support\Facades',
        'app',
        'config',
        'resolve',
        'event',
        'logger',
        'now',
        'cache',
        'view',
        'response',
        'request',
        'validator',
    ]);

/*
 * The stages with real reuse value outside Laravel stay plain PHP. The schema
 * engine normalizes rows and the query layer describes a merge; neither has
 * any business knowing a framework exists.
 */
arch('the schema engine is plain php')
    ->expect('Aldeebhasan\Panorama\Schema')
    ->not->toUse('Illuminate');

arch('every exception the package throws is catchable as one')
    ->expect('Aldeebhasan\Panorama\Exceptions')
    ->toExtend(PanoramaException::class);
