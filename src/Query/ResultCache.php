<?php

namespace Aldeebhasan\Panorama\Query;

use Closure;
use Generator;
use Illuminate\Contracts\Cache\Repository;

/**
 * Keeps the rows of a report for a while, keyed by the report and its
 * parameters, so the same run within that time skips the datasets.
 *
 * The rows are cached as they stream by, and only once they have run out, so
 * a walk abandoned half way caches nothing. A result longer than `$maxRows`
 * is streamed as usual and not cached, so a large report never has to be held
 * in memory or in the store.
 */
final class ResultCache
{
    public function __construct(
        private readonly Repository $cache,
        private readonly int $maxRows = 10000,
        private readonly string $prefix = 'panorama:',
    ) {
    }

    /**
     * @param array<string,mixed> $params
     * @param Closure():Result $fresh
     */
    public function remember(string $report, array $params, int $seconds, Closure $fresh): Result
    {
        $key = $this->key($report, $params);
        $hit = $this->cache->get($key);

        if (is_array($hit) && isset($hit['rows'], $hit['columns'])) {
            /* @var array{rows:list<array<string,mixed>>,columns:list<string>} $hit */
            return new Result($hit['rows'], $hit['columns']);
        }

        $result = $fresh();
        $columns = $result->columns();

        // The columns are given, so the outer result never peeks: the one
        // case where wrapping a result is safe.
        return new Result($this->keep($result, $key, $seconds, $columns), $columns);
    }

    /**
     * @param array<string,mixed> $params
     */
    public function forget(string $report, array $params = []): void
    {
        $this->cache->forget($this->key($report, $params));
    }

    /**
     * @param list<string> $columns
     * @return Generator<int,array<string,mixed>>
     */
    private function keep(Result $result, string $key, int $seconds, array $columns): Generator
    {
        $kept = [];

        foreach ($result->rows() as $row) {
            if ($kept !== null) {
                $kept[] = $row;
                $kept = count($kept) > $this->maxRows ? null : $kept;
            }

            yield $row;
        }

        if ($kept !== null) {
            $this->cache->put($key, ['rows' => $kept, 'columns' => $columns], $seconds);
        }
    }

    /**
     * @param array<string,mixed> $params
     */
    private function key(string $report, array $params): string
    {
        ksort($params);

        return $this->prefix . $report . ':' . hash('xxh128', serialize($params));
    }
}
