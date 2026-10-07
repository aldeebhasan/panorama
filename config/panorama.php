<?php

// config for Hasan Deeb/Panorama
return [
    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    |
    | The reports available to the package. Every entry must be a class
    | implementing Aldeebhasan\Panorama\Contracts\ReportContract, usually by
    | extending Aldeebhasan\Panorama\Reports\Report.
    |
    */
    'reports' => [
        // App\Reports\SalesReport::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Reports are cached per key and per applied filters. A ttl of zero
    | disables caching, and the store defaults to the application one.
    |
    */
    'cache' => [
        'ttl' => (int) env('PANORAMA_CACHE_TTL', 0),
        'store' => env('PANORAMA_CACHE_STORE'),
        'prefix' => 'panorama',
    ],
];
