<?php

namespace Aldeebhasan\Panorama\Connection;

use Generator;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;

/**
 * An HTTP API: base URL, auth and default headers, declared once.
 *
 * Responses are expected to be JSON. Failing statuses raise a client
 * exception, which the dataset turns into a `SourceUnavailable`.
 */
final class HttpConnection implements ConnectionContract
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $auth `['bearer' => '...']` or `['basic' => ['user', 'pass']]`
     */
    public function __construct(
        private readonly string $name,
        private readonly Factory $http,
        private readonly string $baseUrl = '',
        private readonly array $headers = [],
        private readonly array $auth = [],
        private readonly int $timeout = 30,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function driver(): string
    {
        return 'http';
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * A request carrying this connection's base URL, auth and headers.
     */
    public function request(): PendingRequest
    {
        $request = $this->http
            ->baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->acceptJson()
            ->throw();

        if ($this->headers !== []) {
            $request = $request->withHeaders($this->headers);
        }

        $bearer = $this->auth['bearer'] ?? null;
        if (is_string($bearer) && $bearer !== '') {
            $request = $request->withToken($bearer);
        }

        $basic = array_values(is_array($this->auth['basic'] ?? null) ? $this->auth['basic'] : []);
        if (count($basic) === 2 && is_scalar($basic[0]) && is_scalar($basic[1])) {
            $request = $request->withBasicAuth((string) $basic[0], (string) $basic[1]);
        }

        return $request;
    }

    /**
     * Fetch one JSON document.
     *
     * @param array<string,mixed> $query
     * @return array<array-key,mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $json = $this->request()->get($path, $query)->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Walk a paginated collection and yield its rows one at a time.
     *
     * Covers the common page-number shape. A source that paginates by cursor
     * or by `Link` header is better served by its dataset looping over
     * {@see get()} itself.
     *
     * @param array<string,mixed> $query
     * @param string $dataKey where the rows live in the response, dot notation allowed
     * @return Generator<int,mixed>
     */
    public function paginate(
        string $path,
        array $query = [],
        string $dataKey = 'data',
        string $pageParam = 'page',
        string $perPageParam = 'per_page',
        int $perPage = 100,
        int $maxPages = 1000,
    ): Generator {
        for ($page = 1; $page <= $maxPages; $page++) {
            $response = $this->get($path, array_merge($query, [
                $pageParam => $page,
                $perPageParam => $perPage,
            ]));

            $rows = $dataKey === '' ? $response : Arr::get($response, $dataKey);

            if (!is_array($rows) || $rows === []) {
                return;
            }

            yield from array_values($rows);

            // A short page is the last page, so stop without a wasted request.
            if (count($rows) < $perPage) {
                return;
            }
        }
    }
}
