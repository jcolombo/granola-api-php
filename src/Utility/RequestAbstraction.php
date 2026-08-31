<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Utility;

/**
 * An immutable description of one HTTP call, built by Request and executed by Granola.
 *
 * Granola's API takes every filter, cursor and page size as a plain query parameter,
 * so a single `$query` map covers everything the sibling SDKs split across
 * where/include/page/pageSize.
 *
 * @override OVERRIDE-002
 */
class RequestAbstraction
{
    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null  $data
     */
    public function __construct(
        public readonly HttpMethod $method,
        public readonly string $resourceUrl,
        public readonly ?array $data = null,
        public readonly array $query = [],
    ) {}

    /**
     * Deterministic cache key for this call. Query params are sorted so that
     * two equivalent requests built in different orders share a cache entry.
     */
    public function makeCacheKey(): string
    {
        $query = $this->query;
        ksort($query);

        $parts = [
            $this->method->value,
            $this->resourceUrl,
            json_encode($query),
        ];

        return 'grnapi-' . md5(implode('|', $parts));
    }
}
