<?php

// config for Hasan Deeb/Panorama
return [
    /*
    |--------------------------------------------------------------------------
    | Datasets
    |--------------------------------------------------------------------------
    |
    | Datasets are looked up by name, so reports never depend on class names.
    | Each one fetches its own rows. List them explicitly, or point discovery
    | at a directory.
    |
    */
    'datasets' => [
        'register' => [
            // App\Reports\Datasets\Orders::class,
        ],

        'discover' => [
            // app_path('Reports/Datasets') => 'App\Reports\Datasets',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    |
    | The reports available to the package. Every entry must be a class
    | implementing Aldeebhasan\Panorama\Report\ReportContract, usually by
    | extending Aldeebhasan\Panorama\Report\Report.
    |
    */
    'reports' => [
        // App\Reports\SalesReport::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Where rendered reports are written when they are stored rather than
    | streamed back to the caller.
    |
    */
    'storage' => [
        'disk' => env('PANORAMA_DISK', 'local'),
        'path' => 'reports',
    ],
];
