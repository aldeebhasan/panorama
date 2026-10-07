<?php

namespace Aldeebhasan\Panorama\Commands;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:report')]
class MakeReportCommand extends GeneratorCommand
{
    protected $name = 'make:report';
    protected $description = 'Create a new Panorama report class';
    protected $type = 'Report';

    protected function getStub(): string
    {
        return __DIR__ . '/../../stubs/report.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\Reports';
    }

    /**
     * @param string $name
     */
    protected function buildClass($name): string
    {
        return str_replace('{{ reportKey }}', $this->reportKey($name), parent::buildClass($name));
    }

    /**
     * `App\Reports\RevenueByCustomer` becomes `revenue-by-customer`, the same
     * default the Report base class derives.
     */
    private function reportKey(string $class): string
    {
        $basename = basename(str_replace('\\', '/', $class));

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $basename));
    }
}
