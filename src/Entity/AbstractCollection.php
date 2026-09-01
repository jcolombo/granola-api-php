<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Entity;

use Jcolombo\GranolaApiPhp\Configuration;
use Jcolombo\GranolaApiPhp\Granola;
use Jcolombo\GranolaApiPhp\Request;
use Jcolombo\GranolaApiPhp\Utility\RequestResponse;

/**
 * A cursor-paginated list of one resource type.
 *
 * Granola pages with an opaque cursor and a `hasMore` flag rather than page
 * numbers, and warns that a page may hold fewer than `page_size` items while
 * still not being the last one — so paging is driven by `hasMore`, never by
 * counting results.
 *
 * Three ways to read a list, in increasing order of appetite:
 *
 *     $notes->fetch();                       // one page
 *     $notes->fetchAll();                    // every page, all held in memory
 *     foreach ($notes->each() as $note) {}   // every page, one page in memory
 *
 * The cursor is public state, so a long-running sync can stop after any page
 * and resume later:
 *
 *     $cursor = $notes->fetch()->cursor();
 *     // ... next cron run ...
 *     Note::list()->withCursor($cursor)->fetch();
 *
 * @override OVERRIDE-008
 */
abstract class AbstractCollection extends AbstractEntity implements \Iterator, \ArrayAccess, \Countable, \JsonSerializable
{
    /** Key in the response body holding the array of objects, e.g. 'notes'. */
    public const RESULT_KEY = '';

    /** Config key under `pagination` supplying this collection's default page size. */
    protected const PAGE_SIZE_CONFIG = '';

    /** @var list<AbstractResource> */
    protected array $data = [];

    /** @var array<string, int> resource id => position in $data */
    protected array $idIndex = [];

    protected int $index = 0;

    /** @var class-string<AbstractResource> */
    protected string $resourceClass;

    /** @var array<string, mixed> query parameters other than cursor/page_size */
    protected array $query = [];

    protected ?int $pageSize = null;

    protected ?string $cursor = null;

    protected bool $hasMore = false;

    protected bool $fetched = false;

    protected ?int $maxPages = null;

    protected int $pagesFetched = 0;

    /** The raw response of the most recent page fetch (null before any fetch). */
    protected ?RequestResponse $lastResponse = null;

    /**
     * @param class-string<AbstractResource> $resourceClass
     */
    public function __construct(string $resourceClass, null|string|Granola $connection = null)
    {
        parent::__construct($connection);
        $this->resourceClass = $resourceClass;
        $this->maxPages = (int) Configuration::get('pagination.maxPages', 1000);
    }

    // ── Fluent configuration ────────────────────────────────────────────

    /**
     * Items per page. Granola caps this at 30 for notes, folders and audit
     * events, and 100 for transcripts; it clamps anything larger itself.
     */
    public function pageSize(int $size): static
    {
        $this->pageSize = $size;
        return $this;
    }

    /**
     * Safety limit for fetchAll()/each(). Null removes the limit.
     */
    public function maxPages(?int $pages): static
    {
        $this->maxPages = $pages;
        return $this;
    }

    /**
     * Resume from a cursor returned by an earlier fetch.
     */
    public function withCursor(?string $cursor): static
    {
        $this->cursor = $cursor;
        return $this;
    }

    /**
     * The raw response of the most recent page fetch (null before any
     * fetch). This is the only way to distinguish an empty list from a
     * failed request — a non-2xx fetch yields an empty page, it does not
     * throw.
     */
    public function lastResponse(): ?RequestResponse
    {
        return $this->lastResponse;
    }

    /**
     * True when at least one page was fetched and the most recent fetch
     * came back non-2xx (bad credentials, outage, rate-limit exhaustion).
     */
    public function lastFetchFailed(): bool
    {
        return $this->lastResponse !== null && !$this->lastResponse->success;
    }

    /**
     * Set a raw query parameter. An escape hatch for parameters Granola adds
     * after this SDK version shipped.
     */
    public function filter(string $key, mixed $value): static
    {
        $this->query[$key] = $value;
        return $this;
    }

    // ── Fetching ────────────────────────────────────────────────────────

    /**
     * Fetch a single page, replacing anything already loaded.
     */
    public function fetch(): static
    {
        $this->data = [];
        $this->idIndex = [];
        $this->pagesFetched = 0;

        return $this->loadPage($this->cursor);
    }

    /**
     * Fetch the next page and append it. No-op once the list is exhausted.
     */
    public function fetchNext(): static
    {
        if ($this->fetched && !$this->hasMore) {
            return $this;
        }
        return $this->loadPage($this->cursor);
    }

    /**
     * Fetch every remaining page and hold all of it in memory.
     *
     * Bounded by maxPages. For large note archives prefer each().
     */
    public function fetchAll(): static
    {
        // Already complete — either seeded from content that arrived inline, or
        // walked to the end. Re-running would discard what is loaded and spend
        // requests reproducing it. Call rewindPages() to deliberately re-query.
        if ($this->fetched && !$this->hasMore) {
            return $this;
        }

        // Partially loaded: continue from where the last fetch stopped rather
        // than starting over.
        if (!$this->fetched) {
            $this->fetch();
        }

        while ($this->hasMore && !$this->reachedPageLimit()) {
            $this->loadPage($this->cursor);
        }

        return $this;
    }

    /**
     * Walk every item across every page, holding only one page at a time.
     *
     * @return \Generator<int, AbstractResource>
     */
    public function each(): \Generator
    {
        // Yield anything already loaded before paging on. Without this, a
        // collection seeded from an inline transcript would re-request content
        // it already holds, and one that had fetch() called on it would resume
        // from the *next* cursor and silently skip its first page.
        if ($this->fetched) {
            yield from $this->data;

            if (!$this->hasMore) {
                return;
            }
        }

        $cursor = $this->cursor;
        $pages = 0;

        do {
            $this->data = [];
            $this->idIndex = [];
            $this->pagesFetched = 0;
            $this->loadPage($cursor);

            foreach ($this->data as $resource) {
                yield $resource;
            }

            $cursor = $this->cursor;
            $pages++;
        } while ($this->hasMore && ($this->maxPages === null || $pages < $this->maxPages));
    }

    /**
     * Reset to an unfetched state, keeping filters and page size.
     */
    public function rewindPages(): static
    {
        $this->data = [];
        $this->idIndex = [];
        $this->index = 0;
        $this->cursor = null;
        $this->hasMore = false;
        $this->fetched = false;
        $this->pagesFetched = 0;
        return $this;
    }

    // ── Pagination state ────────────────────────────────────────────────

    /**
     * Whether Granola reported further pages after the last one fetched.
     */
    public function hasMore(): bool
    {
        return $this->hasMore;
    }

    /**
     * The cursor for the next page, or null at the end of the list.
     */
    public function cursor(): ?string
    {
        return $this->cursor;
    }

    public function pagesFetched(): int
    {
        return $this->pagesFetched;
    }

    // ── Access ──────────────────────────────────────────────────────────

    /**
     * @return list<AbstractResource>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function first(): ?AbstractResource
    {
        return $this->data[0] ?? null;
    }

    public function last(): ?AbstractResource
    {
        return $this->data === [] ? null : $this->data[count($this->data) - 1];
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    /**
     * Look one resource up by its Granola ID.
     */
    public function find(string $id): ?AbstractResource
    {
        $position = $this->idIndex[$id] ?? null;
        return $position === null ? null : $this->data[$position];
    }

    /**
     * Pull a single property out of every loaded resource.
     *
     * @return list<mixed>
     */
    public function flatten(string $property): array
    {
        return array_map(static fn (AbstractResource $r): mixed => $r->get($property), $this->data);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (AbstractResource $r): array => $r->toArray(), $this->data);
    }

    // ── Subclass hooks ──────────────────────────────────────────────────

    /**
     * The URL this collection reads from. Overridden where the path is nested.
     */
    protected function endpoint(): string
    {
        /** @var class-string<AbstractResource> $class */
        $class = $this->resourceClass;
        return $class::API_PATH;
    }

    /**
     * Query parameters for the next request, cursor and page size aside.
     *
     * @return array<string, mixed>
     */
    protected function queryParameters(): array
    {
        return $this->query;
    }

    /**
     * The response key holding this collection's array of objects.
     *
     * A method rather than a bare constant so the generic EntityCollection can
     * decide it at runtime.
     */
    protected function resultKey(): string
    {
        return static::RESULT_KEY;
    }

    /**
     * Append an already-built resource, keeping the id index in step.
     */
    protected function push(AbstractResource $resource): void
    {
        $this->data[] = $resource;
        $id = $resource->id();
        if ($id !== null) {
            $this->idIndex[$id] = count($this->data) - 1;
        }
    }

    /**
     * Build one resource from a decoded API object.
     *
     * @param array<string, mixed> $data
     */
    protected function makeResource(array $data): AbstractResource
    {
        /** @var class-string<AbstractResource> $class */
        $class = $this->resourceClass;
        return $class::new($this->connection ?? $this->connectionName)
            ->setConnection($this->connection())
            ->hydrate($data);
    }

    // ── Internals ───────────────────────────────────────────────────────

    private function loadPage(?string $cursor): static
    {
        $query = $this->queryParameters();

        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }

        $pageSize = $this->pageSize ?? $this->defaultPageSize();
        if ($pageSize !== null) {
            $query['page_size'] = $pageSize;
        }

        $response = Request::get($this->connection(), $this->endpoint(), $query);

        $this->lastResponse = $response;
        $this->fetched = true;
        $this->pagesFetched++;

        if (!$response->success || $response->body === null) {
            $this->hasMore = false;
            $this->cursor = null;
            return $this;
        }

        $results = $response->body[$this->resultKey()] ?? [];
        if (is_array($results)) {
            foreach ($results as $item) {
                if (is_array($item)) {
                    $this->push($this->makeResource($item));
                }
            }
        }

        // Endpoints that return a complete list (webhook endpoints) omit both
        // fields; absent means "there is no next page".
        $this->hasMore = (bool) ($response->body['hasMore'] ?? false);
        $nextCursor = $response->body['cursor'] ?? null;
        $this->cursor = is_string($nextCursor) && $nextCursor !== '' ? $nextCursor : null;

        if ($this->cursor === null) {
            $this->hasMore = false;
        }

        return $this;
    }

    private function defaultPageSize(): ?int
    {
        if (static::PAGE_SIZE_CONFIG === '') {
            return null;
        }
        $size = Configuration::get('pagination.' . static::PAGE_SIZE_CONFIG);
        return is_int($size) ? $size : null;
    }

    private function reachedPageLimit(): bool
    {
        return $this->maxPages !== null && $this->pagesFetched >= $this->maxPages;
    }

    // ── Iterator ────────────────────────────────────────────────────────

    public function current(): AbstractResource
    {
        return $this->data[$this->index];
    }

    public function key(): int
    {
        return $this->index;
    }

    public function next(): void
    {
        $this->index++;
    }

    public function rewind(): void
    {
        $this->index = 0;
    }

    public function valid(): bool
    {
        return isset($this->data[$this->index]);
    }

    // ── ArrayAccess ─────────────────────────────────────────────────────

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) ? isset($this->idIndex[$offset]) : isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): ?AbstractResource
    {
        if (is_string($offset)) {
            return $this->find($offset);
        }
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!$value instanceof AbstractResource) {
            throw new \InvalidArgumentException('Only AbstractResource instances belong in a Granola collection.');
        }
        if ($offset === null) {
            $this->data[] = $value;
        } else {
            $this->data[(int) $offset] = $value;
        }
        $id = $value->id();
        if ($id !== null) {
            $this->idIndex[$id] = array_search($value, $this->data, true) ?: 0;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        if (is_string($offset)) {
            $position = $this->idIndex[$offset] ?? null;
            if ($position !== null) {
                unset($this->data[$position], $this->idIndex[$offset]);
                $this->data = array_values($this->data);
            }
            return;
        }
        unset($this->data[$offset]);
        $this->data = array_values($this->data);
    }

    // ── Countable / JsonSerializable ────────────────────────────────────

    /**
     * Items currently loaded — not the total available on the server.
     */
    public function count(): int
    {
        return count($this->data);
    }

    /**
     * @return list<AbstractResource>
     */
    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
