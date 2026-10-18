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
    | History
    |--------------------------------------------------------------------------
    |
    | Record every rendered report in a table: what ran, with which
    | parameters, for whom, where it went, how long it took and why it failed.
    | Publish and run the migration before enabling it:
    |
    |     php artisan vendor:publish --tag=panorama-migrations
    |
    | `keep_days` is how long `reports:prune` keeps a run for.
    |
    */
    'history' => [
        'enabled' => env('PANORAMA_HISTORY', false),
        'connection' => env('PANORAMA_HISTORY_CONNECTION'),
        'table' => 'report_runs',
        'keep_days' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Reports run with Panorama::queue() are rendered by a worker, stored on
    | the storage disk below, and announced to whoever asked once they are
    | ready, or once they have failed. Reports up to `attach_up_to` bytes are
    | attached to the mail; larger ones are named by path. 0 never attaches.
    |
    | The notifications may be replaced by classes extending the built-ins.
    |
    */
    'queue' => [
        'connection' => env('PANORAMA_QUEUE_CONNECTION'),
        'queue' => env('PANORAMA_QUEUE'),
        'channels' => ['mail'],
        'attach_up_to' => 10 * 1024 * 1024,
        'notifications' => [
            'ready' => Aldeebhasan\Panorama\Notifications\ReportReady::class,
            'failed' => Aldeebhasan\Panorama\Notifications\ReportFailed::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | A report that sets `$cacheFor` keeps its rows in this cache store for
    | that many seconds, per set of parameters. Results longer than
    | `max_rows` are never cached. Null uses the default store.
    |
    */
    'cache' => [
        'store' => env('PANORAMA_CACHE_STORE'),
        'max_rows' => 10000,
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
