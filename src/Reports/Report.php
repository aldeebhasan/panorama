<?php

namespace Aldeebhasan\Panorama\Reports;

use Aldeebhasan\Panorama\Contracts\ReportContract;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;

abstract class Report implements ReportContract
{
    use Conditionable;
    use Macroable;

    /**
     * The unique key of the report, defaults to the kebab-cased class basename.
     */
    protected string $key = '';

    /**
     * The human readable name of the report, defaults to the headlined class basename.
     */
    protected string $name = '';

    /**
     * The filters the report accepts, any other filter is discarded.
     *
     * @var array<int,string>
     */
    protected array $allowedFilters = [];

    /**
     * @var array<string,mixed>
     */
    protected array $filters = [];

    public function key(): string
    {
        return $this->key ?: Str::kebab(class_basename(static::class));
    }

    public function name(): string
    {
        return $this->name ?: Str::headline(class_basename(static::class));
    }

    public function filters(array $filters): static
    {
        $this->filters = empty($this->allowedFilters)
            ? $filters
            : array_intersect_key($filters, array_flip($this->allowedFilters));

        return $this;
    }

    /**
     * @return array<string,mixed>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    protected function filter(string $key, mixed $default = null): mixed
    {
        return data_get($this->filters, $key, $default);
    }

    /**
     * The lifetime of the cached report in seconds, zero disables caching.
     */
    public function cacheTtl(): int
    {
        $ttl = config('panorama.cache.ttl', 0);

        return is_numeric($ttl) ? (int) $ttl : 0;
    }

    /**
     * The cache key of the report for the currently applied filters.
     */
    public function cacheKey(): string
    {
        $prefix = config('panorama.cache.prefix', 'panorama');
        $prefix = is_string($prefix) ? $prefix : 'panorama';

        return sprintf('%s:%s:%s', $prefix, $this->key(), md5(serialize($this->filters)));
    }
}
