<?php

namespace Aldeebhasan\Panorama\Tests;

use Aldeebhasan\Panorama\PanoramaServiceProvider;
use Aldeebhasan\Panorama\Tests\Reports\SampleReport;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PanoramaServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('cache.default', 'array');
        config()->set('panorama.reports', [
            SampleReport::class,
        ]);
    }
}
