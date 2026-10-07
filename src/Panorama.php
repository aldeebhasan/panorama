<?php

namespace Aldeebhasan\Panorama;

use Aldeebhasan\Panorama\Contracts\ReportUI;
use Aldeebhasan\Panorama\Exceptions\ReportingException;
use Aldeebhasan\Panorama\Reports\Report;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

class Panorama
{
    /**
     * The registered reports, keyed by their report key. Null until the
     * reports declared in the config have been loaded.
     *
     * @var array<string,class-string<ReportUI>|ReportUI>|null
     */
    private ?array $reports = null;

    /**
     * Register a report, either as a class name or as a ready made instance.
     *
     * @param class-string<ReportUI>|ReportUI $report
     *
     * @throws ReportingException
     */
    public function register(string|ReportUI $report): self
    {
        $this->bootRegistry();

        if (is_string($report) && !is_subclass_of($report, ReportUI::class)) {
            throw ReportingException::invalidReport($report);
        }

        $instance = is_string($report) ? app($report) : $report;
        $this->reports[$instance->key()] = $report;

        return $this;
    }

    /**
     * Resolve a fresh instance of the given report.
     *
     * @throws ReportingException
     */
    public function report(string $key): ReportUI
    {
        $this->bootRegistry();

        $report = $this->reports[$key] ?? throw ReportingException::reportNotFound($key);

        return is_string($report) ? app($report) : clone $report;
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
        if ($this->reports !== null) {
            return;
        }

        $this->reports = [];
        foreach ((array) config('panorama.reports', []) as $report) {
            $this->register($report);
        }
    }

    private function cache(): CacheRepository
    {
        return Cache::store(config('panorama.cache.store'));
    }
}
