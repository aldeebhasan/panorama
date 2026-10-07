<?php

namespace Aldeebhasan\Panorama\Commands;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:dataset')]
class MakeDatasetCommand extends GeneratorCommand
{
    protected $name = 'make:dataset';
    protected $description = 'Create a new Panorama dataset class';
    protected $type = 'Dataset';

    protected function getStub(): string
    {
        return __DIR__ . '/../../stubs/dataset.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\Reports\Datasets';
    }

    /**
     * @param string $name
     */
    protected function buildClass($name): string
    {
        return str_replace(
            '{{ datasetName }}',
            $this->datasetName($name),
            parent::buildClass($name),
        );
    }

    /**
     * Turn `App\Reports\Datasets\CrmCustomers` into `crm_customers`, the same
     * default the Dataset base class derives.
     */
    private function datasetName(string $class): string
    {
        $basename = basename(str_replace('\\', '/', $class));

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $basename));
    }
}
