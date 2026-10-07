<?php

// config for Hasan Deeb/Panorama
return [
    /*
    |--------------------------------------------------------------------------
    | Workspace
    |--------------------------------------------------------------------------
    |
    | The engine datasets are loaded into and merged in. Datasets and reports
    | are identical across engines, so this is a deployment choice.
    |
    | Supported: "database"
    |
    */
    'workspace' => env('PANORAMA_WORKSPACE', 'database'),

    'workspaces' => [
        'database' => [
            /*
             * The application connection temporary tables are created on.
             * Null uses the default connection, which is what lets a merge
             * join straight against live application tables.
             */
            'connection' => env('PANORAMA_WORKSPACE_CONNECTION'),

            /*
             * Prefix for the tables one run creates, so a leftover table is
             * always recognisable.
             */
            'prefix' => 'panorama_',

            /*
             * Rows per insert while loading a dataset.
             */
            'chunk' => 500,

            /*
             * Temporary tables vanish with the connection, which is what you
             * want. Turn this off for managed databases that do not grant
             * CREATE TEMPORARY TABLE; real tables are then created and dropped
             * at the end of the run instead.
             */
            'temporary' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | How to reach each system, declared once and shared by many datasets.
    |
    | Drivers: "database", "http", "array"
    |
    */
    'connections' => [
        'default' => [
            'driver' => 'database',
            'connection' => null,
        ],

        // 'crm_api' => [
        //     'driver' => 'http',
        //     'base_url' => env('CRM_URL'),
        //     'auth' => ['bearer' => env('CRM_TOKEN')],
        //     'timeout' => 30,
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Datasets
    |--------------------------------------------------------------------------
    |
    | Datasets are looked up by name, so reports never depend on class names.
    | List them explicitly, or point discovery at a directory.
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
    | implementing Aldeebhasan\Panorama\Contracts\ReportContract, usually by
    | extending Aldeebhasan\Panorama\Reports\Report.
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
