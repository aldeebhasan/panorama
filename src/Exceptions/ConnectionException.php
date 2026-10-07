<?php

namespace Aldeebhasan\Panorama\Exceptions;

/**
 * Thrown when a connection is missing, misconfigured, or of the wrong kind for
 * the dataset asking for it.
 */
class ConnectionException extends PanoramaException
{
    /**
     * @param list<string> $known
     */
    public static function unknown(string $name, array $known = []): self
    {
        $message = sprintf('No connection is configured under the name [%s].', $name);

        if ($known !== []) {
            sort($known);
            $message .= sprintf(' Configured connections are [%s].', implode(', ', $known));
        }

        return new self($message);
    }

    public static function unknownDriver(string $name, string $driver): self
    {
        return new self(sprintf(
            'The connection [%s] declares the unknown driver [%s]. Use database, http or array.',
            $name,
            $driver,
        ));
    }

    public static function wrongKind(string $dataset, string $connection, string $driver, string $expected): self
    {
        return new self(sprintf(
            'Dataset [%s] asked for a %s connection but [%s] is a %s connection.',
            $dataset,
            $expected,
            $connection,
            $driver,
        ));
    }

    public static function missingOption(string $name, string $option): self
    {
        return new self(sprintf('The connection [%s] is missing the required [%s] option.', $name, $option));
    }

    public static function noConnection(string $dataset): self
    {
        return new self(sprintf(
            'Dataset [%s] has no connection. Declare one with $connection, or let the registry resolve it.',
            $dataset,
        ));
    }
}
