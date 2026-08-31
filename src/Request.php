<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp;

use Jcolombo\GranolaApiPhp\Utility\Converter;
use Jcolombo\GranolaApiPhp\Utility\HttpMethod;
use Jcolombo\GranolaApiPhp\Utility\RequestAbstraction;
use Jcolombo\GranolaApiPhp\Utility\RequestResponse;

/**
 * Builds RequestAbstraction objects for Granola's fixed endpoint set and hands
 * them to a connection to execute.
 *
 * Granola exposes a small, flat set of URLs, so this is a thin path/query
 * builder rather than the entity-key indirection the CRUD-heavy sibling SDKs
 * need.
 *
 * @override OVERRIDE-002
 */
class Request
{
    /**
     * @param array<string, mixed> $query
     */
    public static function get(Granola $connection, string $path, array $query = []): RequestResponse
    {
        return $connection->execute(new RequestAbstraction(
            method: HttpMethod::GET,
            resourceUrl: $path,
            query: self::compileQuery($query),
        ));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function post(Granola $connection, string $path, array $data): RequestResponse
    {
        return $connection->execute(new RequestAbstraction(
            method: HttpMethod::POST,
            resourceUrl: $path,
            data: $data,
        ));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function patch(Granola $connection, string $path, array $data): RequestResponse
    {
        return $connection->execute(new RequestAbstraction(
            method: HttpMethod::PATCH,
            resourceUrl: $path,
            data: $data,
        ));
    }

    public static function delete(Granola $connection, string $path): RequestResponse
    {
        return $connection->execute(new RequestAbstraction(
            method: HttpMethod::DELETE,
            resourceUrl: $path,
        ));
    }

    /**
     * Join URL segments, encoding each one.
     *
     *     Request::path('v1', 'notes', $noteId, 'transcript')
     *
     * Traversal segments are rejected rather than encoded: an ID that arrives
     * as `..` is not a resource, it is an attempt to reach a different endpoint,
     * and IDs routinely come from webhook payloads and user input.
     *
     * @throws \InvalidArgumentException on a `.` or `..` path segment
     */
    public static function path(string ...$segments): string
    {
        $clean = [];
        foreach ($segments as $segment) {
            // A segment may itself be a path fragment such as 'v1/notes', so
            // split before encoding — otherwise the separator gets escaped.
            foreach (explode('/', trim($segment, '/')) as $part) {
                if ($part === '') {
                    continue;
                }
                if ($part === '.' || $part === '..') {
                    throw new \InvalidArgumentException(
                        "Refusing to build a Granola URL containing the path segment '{$part}'."
                    );
                }
                $clean[] = rawurlencode($part);
            }
        }
        return implode('/', $clean);
    }

    /**
     * Drop null/empty params and flatten values into their query representation.
     *
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    private static function compileQuery(array $query): array
    {
        $compiled = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $compiled[$key] = Converter::convertForQuery($value);
        }
        return $compiled;
    }
}
