<?php

namespace Aldeebhasan\Panorama\Dataset;

/**
 * The filters one run was given, read only once built.
 *
 * Datasets reach values as properties, `$params->from`, so a `fetch()` reads
 * the way the design sketch writes it. The typed readers exist for the cases
 * where a raw `mixed` would spread through the call site.
 */
final class Params
{
    /**
     * @param array<string,mixed> $values
     */
    public function __construct(private readonly array $values = [])
    {
    }

    /**
     * @param array<array-key,mixed> $values
     */
    public static function make(array $values = []): self
    {
        $cast = [];
        foreach ($values as $key => $value) {
            $cast[(string) $key] = $value;
        }

        return new self($cast);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function __get(string $key): mixed
    {
        return $this->get($key);
    }

    public function __isset(string $key): bool
    {
        return isset($this->values[$key]);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function filled(string $key): bool
    {
        return ($this->values[$key] ?? null) !== null && ($this->values[$key] ?? '') !== '';
    }

    public function string(string $key, ?string $default = null): ?string
    {
        $value = $this->get($key);

        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : $default;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, ?bool $default = null): ?bool
    {
        $value = $this->get($key);

        return is_bool($value) ? $value : $default;
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Keep only the given keys, dropping everything a consumer did not declare.
     *
     * @param list<string> $keys
     */
    public function only(array $keys): self
    {
        return new self(array_intersect_key($this->values, array_flip($keys)));
    }

    /**
     * @param array<string,mixed> $values
     */
    public function merge(array $values): self
    {
        return new self(array_merge($this->values, $values));
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * A stable fingerprint of these values, for cache keys and run logs.
     */
    public function fingerprint(): string
    {
        $values = $this->values;
        ksort($values);

        return md5(serialize($values));
    }
}
