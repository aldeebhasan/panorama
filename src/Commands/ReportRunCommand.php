<?php

namespace Aldeebhasan\Panorama\Commands;

use Aldeebhasan\Panorama\Exceptions\PanoramaException;
use Aldeebhasan\Panorama\Panorama;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'reports:run')]
class ReportRunCommand extends Command
{
    protected $signature = 'reports:run
                            {report : The key of the report to run}
                            {--param=* : Parameters to apply, as key=value pairs}
                            {--format=json : The format to render, csv or json}
                            {--store : Write the report to the configured disk instead of printing it}
                            {--disk= : The disk to store it on}
                            {--path= : The directory to store it in}';
    protected $description = 'Run a report and print it, or store it on a disk';

    public function handle(Panorama $panorama): int
    {
        try {
            $rendered = $panorama
                ->run((string) $this->argument('report'), $this->parameters())
                ->render(is_string($format = $this->option('format')) ? $format : 'json');

            if ($this->option('store')) {
                $path = $rendered->store(
                    is_string($disk = $this->option('disk')) && $disk !== '' ? $disk : null,
                    is_string($dir = $this->option('path')) && $dir !== '' ? $dir : null,
                );

                $this->components->info(sprintf('Stored as [%s].', $path));

                return self::SUCCESS;
            }

            // Streamed to stdout, so a large report never has to be held whole.
            foreach ($rendered->chunks() as $chunk) {
                $this->output->write($chunk);
            }

            return self::SUCCESS;
        } catch (ValidationException $invalid) {
            foreach ($invalid->validator->errors()->all() as $error) {
                $this->components->error((string) $error);
            }

            return self::FAILURE;
        } catch (PanoramaException $failure) {
            $this->components->error($failure->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array<string,string>
     */
    private function parameters(): array
    {
        $parameters = [];
        foreach ((array) $this->option('param') as $parameter) {
            [$name, $value] = array_pad(explode('=', (string) $parameter, 2), 2, '');
            $parameters[$name] = $value;
        }

        return $parameters;
    }
}
