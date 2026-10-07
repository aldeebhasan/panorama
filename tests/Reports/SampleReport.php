<?php

namespace Aldeebhasan\Panorama\Tests\Reports;

use Aldeebhasan\Panorama\Reports\Report;

class SampleReport extends Report
{
    protected string $key = 'sample';
    protected string $name = 'Sample Report';
    protected array $allowedFilters = ['country'];
    public static int $builds = 0;

    public function build(): array
    {
        self::$builds++;

        return [
            'country' => $this->filter('country', 'all'),
            'total' => 10,
        ];
    }
}
