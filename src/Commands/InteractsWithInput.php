<?php

namespace Aldeebhasan\Panorama\Commands;

/**
 * Console input, narrowed once.
 *
 * Symfony declares an argument or an option as `mixed`, because the signature
 * decides the shape: `{name}` arrives as a string, `--param=*` as an array,
 * and a flag as a bool. Casting that straight to a string is what static
 * analysis objects to, and rightly — a repeated option would stringify to the
 * word "Array".
 *
 * This is the same rule the service provider follows for config: narrow at the
 * boundary, so no `mixed` travels any further into the package.
 */
trait InteractsWithInput
{
    /**
     * A single-value argument, as the string the signature promises.
     */
    private function text(string $argument): string
    {
        $value = $this->argument($argument);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * A single-value option, or null when it was not given.
     */
    private function flag(string $option): ?string
    {
        $value = $this->option($option);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * A repeated option (`--param=*`), as the list of strings it collects.
     *
     * @return list<string>
     */
    private function strings(string $option): array
    {
        $values = $this->option($option);

        return array_values(array_map(
            static fn(mixed $value): string => is_scalar($value) ? (string) $value : '',
            is_array($values) ? $values : [],
        ));
    }
}
