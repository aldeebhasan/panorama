<?php

namespace Aldeebhasan\Panorama;

use Aldeebhasan\Panorama\Contracts\ReportContract;
use Aldeebhasan\Panorama\Exceptions\ReportingException;
use Aldeebhasan\Panorama\Reports\Report;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

class Panorama
{
    /**
     * The registered reports, keyed by their report key.
     *
     * @var array<string,string|ReportContract>
     */
    private array $reports = [];

    /**
     * Whether the reports declared in the config have been loaded.
     */
    private bool $booted = false;

    /**
     * Register a report, either as a class name or as a ready made instance.
     * Class names are validated when they are resolved, not when they are given.
     *
     *
     * @throws ReportingException
     */
    public function register(string|ReportContract $report): self
    {
        $this->bootRegistry();

        $instance = is_string($report) ? $this->resolve($report) : $report;
        $this->reports[$instance->key()] = $report;

        return $this;
    }

    /**
     * Resolve a fresh instance of the given report.
     *
     * @throws ReportingException
     */
    public function report(string $key): ReportContract
    {
        $this->bootRegistry();

        $report = $this->reports[$key] ?? throw ReportingException::reportNotFound($key);

        return is_string($report) ? $this->resolve($report) : clone $report;
    }

    /**
     * Build the given report, using the cache when it is enabled.
     *
     * @param array<string,mixed> $filters
     * @return array<array-key,mixed>
     *
     * @throws ReportingException
     */
    public function generate(string $key, array $filters = []): array
    {
        $report = $this->report($key)->filters($filters);

        $ttl = $report instanceof Report ? $report->cacheTtl() : 0;
        if ($ttl <= 0) {
            return $report->build();
        }

        return $this->cache()->remember($report->cacheKey(), $ttl, fn() => $report->build());
    }

    /**
     * Forget the cached payload of the given report for the given filters.
     *
     * @param array<string,mixed> $filters
     *
     * @throws ReportingException
     */
    public function forget(string $key, array $filters = []): bool
    {
        $report = $this->report($key)->filters($filters);

        if (!$report instanceof Report) {
            return false;
        }

        return $this->cache()->forget($report->cacheKey());
    }

    /**
     * All the registered report keys mapped to their names.
     *
     * @return array<string,string>
     *
     * @throws ReportingException
     */
    public function all(): array
    {
        $this->bootRegistry();

        $reports = [];
        foreach (array_keys($this->reports) as $key) {
            $reports[$key] = $this->report($key)->name();
        }

        return $reports;
    }

    /**
     * @throws ReportingException
     */
    public function has(string $key): bool
    {
        $this->bootRegistry();

        return isset($this->reports[$key]);
    }

    /**
     * Load the reports declared in the config, only once.
     *
     * @throws ReportingException
     */
    private function bootRegistry(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        $configured = config('panorama.reports', []);
        foreach (is_array($configured) ? $configured : [] as $report) {
            if (!is_string($report) && !$report instanceof ReportContract) {
                throw ReportingException::invalidReport(get_debug_type($report));
            }

            $this->register($report);
        }
    }

    /**
     * Resolve a report class name out of the container.
     *
     * @throws ReportingException
     */
    private function resolve(string $report): ReportContract
    {
        $instance = class_exists($report) ? app($report) : null;

        if (!$instance instanceof ReportContract) {
            throw ReportingException::invalidReport($report);
        }

        return $instance;
    }

    private function cache(): CacheRepository
    {
        $store = config('panorama.cache.store');

        return Cache::store(is_string($store) ? $store : null);
    }
}
