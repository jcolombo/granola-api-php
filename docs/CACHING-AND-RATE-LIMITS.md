# Caching, rate limits and logging

---

## Rate limits

Granola publishes two limits:

| Limit | Value |
|-------|-------|
| Burst | 25 requests in any 5-second window |
| Sustained | 5 requests/second — 300/minute |

Exceeding either earns a `429 Too Many Requests`.

### What the SDK does

`RateLimiter` tracks request timestamps per API key and waits before a call that would breach either window, rather than making it and being rejected. If a 429 arrives anyway — another process sharing the key, a limit change — it retries up to `rateLimit.maxRetries` times, honouring `Retry-After` when Granola sends it and otherwise doubling `rateLimit.retryDelayMs` per attempt.

`rateLimit.safetyBuffer` (1 by default) holds a request back from each window, so a burst that arrives at the same millisecond as the limiter's own accounting does not tip over.

### The limitation that matters

**The limiter is per PHP process.** It has no shared state, so four workers running against one key will each pace themselves to the full budget and collectively use four times it.

If you run parallel jobs against one key, divide the budget:

```php
Configuration::set('rateLimit.perMinute', (int) (300 / $workerCount));
Configuration::set('rateLimit.burstLimit', (int) (25 / $workerCount));
```

Or give each worker its own key — Granola scopes limits per key:

```php
$granola = Granola::connect($keys[$workerIndex], "worker-{$workerIndex}");
```

### Spending fewer requests

- **Raise the page size.** Notes, folders and audit events cap at 30; transcripts at 100. The default is 10 (50 for transcripts), so a full walk at the cap costs a third of the requests.
- **Ask for the transcript inline.** `Note::find($id, withTranscript: true)` is one request instead of two, for any transcript small enough to inline.
- **Filter server-side.** `updatedAfter()` on an incremental sync beats fetching everything and comparing.
- **Do not fetch a note you will not read.** A webhook event's `note()` is lazy for exactly this reason.
- **Turn on the cache** for repeated reads of the same note within a request or a short-lived job.

### Watching usage

```php
RateLimiter::usage($apiKey);        // requests recorded in the last 60s
RateLimiter::usage($apiKey, 5.0);   // ... in the last 5s
```

---

## Caching

Off by default. It caches successful `GET` responses only, and is cleared entirely by any mutation.

### File-backed

```php
Configuration::set('enabled.cache', true);
Configuration::set('path.cache', '/var/cache/myapp');   // or define GRNAPI_REQUEST_CACHE_PATH
Configuration::set('cache.lifespan', 300);              // seconds
```

Entries land in a `granola-cache/` subdirectory, keyed by an `md5` of the method, URL and sorted query parameters — so two equivalent requests built in different orders share one entry. Age is measured from the file's mtime rather than a stored expiry, which keeps a daylight-saving shift from extending a lifespan by an hour.

### Your own backend

Redis, APCu, PSR-16 — anything, as long as it round-trips a `RequestResponse`:

```php
use Jcolombo\GranolaApiPhp\Cache\Cache;
use Jcolombo\GranolaApiPhp\Utility\RequestResponse;

Cache::registerCacheMethods(
    read: function (string $key) use ($psr16): ?RequestResponse {
        $hit = $psr16->get($key);
        return $hit instanceof RequestResponse ? $hit : null;
    },
    write: function (string $key, RequestResponse $response) use ($psr16): void {
        $psr16->set($key, $response, 300);
    },
    clear: function (?string $key) use ($psr16): void {
        $key === null ? $psr16->clear() : $psr16->delete($key);
    },
);
```

A registered backend is used whether or not `enabled.cache` is set — registering it is the opt-in.

### When not to cache

- **Webhook receivers.** The event says the note just changed; a cached copy is the stale one. Either leave the cache off in that process, or `Cache::clear()` before fetching.
- **Anything user-facing that must be current.** The lifespan is a staleness budget; pick it deliberately.
- **Long-running workers with a large cache directory.** Nothing prunes it but expiry-on-read, so a worker touching many distinct URLs will accumulate files. Sweep it, or use a backend that expires on its own.

### Invalidation

Any non-GET clears the whole cache. Webhook endpoints are the only writable resource, so there is nothing else a mutation could invalidate, and precise pattern-matching would buy one avoided miss in exchange for a class of correctness bugs. A custom backend receives the same `clear(null)` call and can narrow it however it likes.

---

## Logging

Off by default.

```php
Configuration::set('enabled.logging', true);
Configuration::set('path.logs', '/var/log/myapp');   // directory, or a file path
Configuration::set('log.requests', true);            // one line per request
Configuration::set('log.connections', true);         // one line per connection created
```

Request lines carry method, path, status and elapsed time. **API keys are never logged** — a connection logs its fingerprint (`GranolaApi-***abcd`) instead.

### Sending it to your own logger

```php
use Jcolombo\GranolaApiPhp\Utility\Log;

Log::registerWriter(function (string $message, array $context) use ($logger): void {
    $logger->info($message, $context);   // PSR-3, Monolog, whatever you have
});
```

A registered writer receives everything, including error output, and bypasses the file path entirely. Pass `null` to restore file logging.
