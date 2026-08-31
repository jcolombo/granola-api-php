# OVERRIDES — design decisions and intentional divergences

This SDK follows the same shape as its sibling packages (`paymo-api-php`, `niftyquoter-api-php`, `ninety-api-php`, `optmyzr-api-php`, `leadfeeder-api-php`): a `Configuration` singleton over a packaged JSON default, a connection object that executes `RequestAbstraction`s, `Entity/Resource` + `Entity/Collection` classes registered in a `classMap`, and `Utility/` for the cross-cutting pieces.

Granola's API is shaped differently from the CRUD REST APIs those packages wrap, so some of the family's machinery does not apply and some had to be replaced. Every deliberate divergence is recorded here with an `OVERRIDE-NNN` identifier, referenced from the source with an `@override` annotation.

---

## OVERRIDE-001: Native JSON configuration

**Files**: `src/Configuration.php`

Native `json_decode()` with `JSON_THROW_ON_ERROR`, `array_replace_recursive()` for the deep merge, and `adbario/php-dot-notation` for dot access.

**Justification**: the SDK reads one config format. Supporting more would add a dependency for nothing. Identical to the sibling packages.

---

## OVERRIDE-002: A single query map instead of where/include/page/pageSize

**Files**: `src/Request.php`, `src/Utility/RequestAbstraction.php`

`RequestAbstraction` carries `method`, `resourceUrl`, `data` and one `query` map. `Request` is a thin path/query builder with `get`/`post`/`patch`/`delete`, not the entity-key indirection the sibling SDKs use.

**Justification**: Granola exposes six flat, fixed URLs. Every filter, cursor and page size is an ordinary query parameter, and there is no WHERE syntax, no `include` list beyond a single documented value, and no nested resource addressing to abstract. The sibling machinery would be indirection over nothing.

`Request::path()` rejects `.` and `..` segments rather than encoding them. IDs reach it from webhook payloads and user input, and a traversal segment there is an attempt to redirect the request at a different endpoint, not a resource identifier.

---

## OVERRIDE-003: Dual burst/sustained rate limiter

**Files**: `src/Utility/RateLimiter.php`

Two sliding windows — 25 requests per 5 seconds, and 300 per 60 seconds — rather than the siblings' per-minute/per-hour pair. A 429 honours `Retry-After` when the header is present, and otherwise backs off exponentially.

**Justification**: those are Granola's published numbers (25-request burst over 5 seconds, 5 requests/second sustained). Granola does not document rate-limit response headers, so the windows are tracked locally from request timestamps; `Retry-After` is handled defensively in case it appears.

The limiter is per key and per process. It cannot coordinate across workers, which is why `rateLimit.perMinute` is configurable.

---

## OVERRIDE-004: Named connection registry with a configured default

**Files**: `src/Granola.php`

`Granola::connect(?string $apiKey = null, ?string $name = null)`. With no arguments it uses `connection.apiKey` from configuration. Named connections live alongside it and are retrieved with `Granola::connection($name)`; resources accept a name wherever they accept a connection.

**Justification**: the requirement was a default key that additional keys can be added to, not swapped for. Granola makes this concrete: a personal key and a workspace key see genuinely different sets of notes, and an application may need both in one request. Keying the registry by API key means connecting twice with the same key is free, and the alias layer keeps call sites from having to pass keys around.

`getFingerprint()` returns `GranolaApi-***<last four>` so a connection can be logged without leaking the key.

---

## OVERRIDE-005: Cache path from config or constant

**Files**: `src/Cache/Cache.php`, `src/Cache/ScrubCache.php`

Caching activates when `enabled.cache` is true **and** a directory is known — from either the `GRNAPI_REQUEST_CACHE_PATH` constant (sibling convention) or the `path.cache` config key.

**Justification**: requiring a global PHP constant to enable a cache is awkward in modern applications and untestable. The constant is still honoured for consistency with the siblings.

`ScrubCache::invalidate()` clears everything on a mutation. Webhook endpoints are the only writable resource, so there is nothing else a mutation could invalidate, and pattern-matching URLs to save one cache miss is not worth the edge cases.

---

## OVERRIDE-006: HTTP statuses are responses, not exceptions

**Files**: `src/Granola.php` (`execute()`)

Guzzle runs with `http_errors => false`, and a non-2xx returns a `RequestResponse` with `success === false`. `Error::handleApiError()` reports it, and throws `ApiException` only when `error.throwOnApiError` is enabled.

**Justification**: the siblings achieve the same non-throwing behaviour by catching three Guzzle exception classes. Turning the errors off is the same outcome in a third of the code, and it makes 413 — an expected, recoverable answer on Get Note rather than a failure — a normal branch instead of an exception caught and discarded.

Authentication headers and `http_errors` are applied **per request**, not only in the client's defaults, and request URLs are absolute rather than relative to a `base_uri`. Without that, a client injected through `setHttpClient()` — a proxy, retry middleware, a test double — would silently lose authentication and start 401ing.

---

## OVERRIDE-007: No CRUD on AbstractResource

**Files**: `src/Entity/AbstractResource.php`, `src/Entity/Resource/WebhookEndpoint.php`

`AbstractResource` provides hydration, typed property access, dirty tracking and serialisation. It does **not** provide `create()`, `update()` or `delete()`. `WebhookEndpoint` defines those itself.

**Justification**: Granola's API is read-only except for webhook endpoints. Inheriting `create()` onto `Note` would put a method on the public surface that can only ever fail. Dirty tracking stays on the base class because `WebhookEndpoint::save()` genuinely uses it — Granola's update is a PATCH, and sending only what changed is the correct request.

Response fields the SDK does not model are kept in `unmapped()` rather than discarded, so a Granola addition is visible to callers before this package catches up.

---

## OVERRIDE-008: Cursor pagination, not page numbers

**Files**: `src/Entity/AbstractCollection.php`

Paging is driven by the `hasMore` flag and an opaque `cursor`, both exposed publicly. `fetch()` reads one page, `fetchNext()` appends the next, `fetchAll()` follows every cursor, and `each()` yields a `Generator` that holds one page at a time.

**Justification**: Granola documents cursor pagination and warns explicitly that a page may hold fewer items than `page_size` while still not being the last one — so the siblings' "stop when a page isn't full" rule would silently truncate results. `each()` exists because a note archive can be large enough that `fetchAll()` is the wrong shape, and the public cursor exists because an incremental sync needs to stop after a page and resume in the next run.

Collections carry a `maxPages` bound so a mistaken filter cannot loop indefinitely, and every concrete collection narrows its accessors' return types, so `Note::list()->first()` is a `Note` rather than an `AbstractResource`.

---

## OVERRIDE-009: Standard Webhooks verification, no dispatcher

**Files**: `src/Webhook/*`

The webhook subsystem verifies and parses. It has no dispatcher, no listener registry, no handler auto-discovery and no delivery deduplication store.

**Justification**: routing, queueing, retry policy and deduplication are application concerns that every framework already solves, and a dispatcher here would compete with the host application's own. What is genuinely worth centralising is the part that is easy to get subtly wrong: the signature scheme, and turning the payload into typed objects.

Specifics:

- Verification is against the **raw** body, with `hash_equals()` for constant-time comparison.
- The `webhook-signature` header may carry several space-separated `v1,<sig>` entries; any match passes, so a secret rotation does not drop deliveries.
- Timestamp drift is rejected in both directions, defaulting to 300 seconds.
- An **unknown event type is not an error**. It parses with `type === null` and the original string in `rawType`, so a receiver keeps working when Granola ships a new event.
- `WebhookEvent::note()` is lazy and memoised. Granola's payload names a note but does not carry it, and fetching one on every delivery would spend rate limit on events a handler may ignore.
- `WebhookHeaders` accepts `$_SERVER`, `getallheaders()`, PSR-7 header arrays or any object exposing `getHeaders()`, without this package depending on PSR-7.
- `Webhook::parseUnverified()` is named to be hard to reach for by accident.
