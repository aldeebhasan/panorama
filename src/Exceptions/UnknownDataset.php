<?php

namespace Aldeebhasan\Panorama\Exceptions;

use Aldeebhasan\Panorama\Dataset\Dataset;

/**
 * Thrown when a report asks for a dataset that is not registered.
 */
class UnknownDataset extends PanoramaException
{
    /**
     * @param list<string> $known
     */
    public static function named(string $name, array $known = []): self
    {
        $message = sprintf('No dataset is registered under the name [%s].', $name);

        if ($known !== []) {
            sort($known);
            $message .= sprintf(' Known datasets are [%s].', implode(', ', $known));
        }

        return new self($message);
    }

    public static function notADataset(string $class): self
    {
        return new self(sprintf(
            '[%s] cannot be registered as a dataset, it does not extend [%s].',
            $class,
            Dataset::class,
        ));
    }
}
