# Configuration

Every setting lives in one dot-notation store. Defaults ship in `default.granolaapi.config.json` at the package root; your application layers its own values on top.

```php
use Jcolombo\GranolaApiPhp\Configuration;
```

---

## Loading

Each load **deep-merges**, so an override file only needs the keys it changes. Sibling keys under the same branch survive.

```php
// A directory — looks for granolaapi.config.json inside it. Silent if absent.
Configuration::overload(__DIR__ . '/config');

// An explicit file. Throws JsonException on malformed JSON.
Configuration::load('/etc/myapp/granola.json');

// Individual values, at runtime.
Configuration::set('connection.apiKey', getenv('GRANOLA_API_KEY'));
```

`overload()` is the one to reach for at boot: it does nothing when the file is missing, so the same code works in an environment that has no local override.

Order matters — later loads win:

```php
Configuration::overload(__DIR__ . '/config');            // committed defaults
Configuration::overload('/etc/myapp');                   // per-environment
Configuration::set('connection.apiKey', getenv('...'));  // per-process secrets
```

### Reading back

```php
Configuration::get('connection.timeout');          // 30
Configuration::get('nothing.here', 'fallback');    // 'fallback'
Configuration::has('connection.apiKey');           // bool
Configuration::all();                              // the whole merged array
Configuration::loadedPaths();                      // every file merged, defaults first
Configuration::reset();                            // back to packaged defaults
```

### Keeping secrets out of the repository

The `.gitignore` shipped with this package already excludes `granolaapi.config.json`, so the conventional filename is safe to use for local credentials. In production, prefer the environment:

```php
Configuration::set('connection.apiKey', getenv('GRANOLA_API_KEY'));
Configuration::set('webhook.signingSecret', getenv('GRANOLA_WEBHOOK_SECRET'));
```

---

## Reference

### `connection`

| Key | Type | Default | Notes |
|-----|------|---------|-------|
| `connection.url` | string | `https://public-api.granola.ai/` | Base URL. Override for a proxy or a recording test double. |
| `connection.apiKey` | ?string | `null` | The default key. `Granola::connect()` with no arguments uses it, and throws `ConfigurationException` when it is unset. |
| `connection.timeout` | int | `30` | HTTP timeout in seconds. |
| `connection.verify` | bool | `true` | TLS peer verification. Leave on. |

### `webhook`

| Key | Type | Default | Notes |
|-----|------|---------|-------|
| `webhook.signingSecret` | ?string | `null` | Default secret for `Webhook::parse()` when none is passed. Each endpoint has its own; set this only if you run one. |
| `webhook.toleranceSeconds` | int | `300` | Accepted drift between `webhook-timestamp` and now, in both directions. Lower is stricter but less forgiving of clock skew; below ~60 you will start rejecting genuine deliveries. |
| `webhook.requireHttps` | bool | `true` | Reserved for endpoint registration validation. |

### `notes`

| Key | Type | Default | Notes |
|-----|------|---------|-------|
| `notes.autoFallbackLargeTranscript` | bool | `true` | On `413 TRANSCRIPT_TOO_LARGE`, silently re-fetch the note without the transcript and let `transcript()` page it. Set `false` to get a `TranscriptTooLargeException` instead. |

### `pagination`

| Key | Type | Default | Granola's cap |
|-----|------|---------|---------------|
| `pagination.notesPageSize` | int | `10` | 30 |
| `pagination.foldersPageSize` | int | `10` | 30 |
| `pagination.auditPageSize` | int | `10` | 30 |
| `pagination.transcriptPageSize` | int | `50` | 100 |
| `pagination.maxPages` | int | `1000` | Bound on `fetchAll()` and `each()`. `->maxPages(null)` removes it per collection. |

Raising a page size to Granola's cap makes a full walk cheaper in requests. Granola clamps anything larger itself.

### `rateLimit`

| Key | Type | Default | Notes |
|-----|------|---------|-------|
| `rateLimit.enabled` | bool | `true` | Turn off only when something upstream already paces requests. |
| `rateLimit.burstLimit` | int | `25` | Granola's burst allowance. |
| `rateLimit.burstWindowSeconds` | int | `5` | The window that allowance applies to. |
| `rateLimit.perMinute` | int | `300` | Sustained rate (5/second). Divide this by your worker count if several processes share one key. |
| `rateLimit.safetyBuffer` | int | `1` | Requests held back from each window. |
| `rateLimit.minDelayMs` | int | `0` | Optional floor between calls. |
| `rateLimit.maxRetries` | int | `3` | Retries after a 429. |
| `rateLimit.retryDelayMs` | int | `1000` | Base backoff, doubled per attempt. `Retry-After` wins when Granola sends it. |

### `enabled`, `path`, `cache`, `log`

| Key | Type | Default | Notes |
|-----|------|---------|-------|
| `enabled.cache` | bool | `false` | Cache GET responses. Needs a path (below). |
| `enabled.logging` | bool | `false` | Write a request log. Needs `path.logs`. |
| `path.cache` | ?string | `null` | Cache directory. The `GRNAPI_REQUEST_CACHE_PATH` constant is honoured too. |
| `path.logs` | ?string | `null` | A log file, or a directory to place `error.logFilename` in. |
| `cache.lifespan` | int | `300` | Cache entry lifetime in seconds. |
| `log.connections` | bool | `false` | Log each connection as it is created. |
| `log.requests` | bool | `true` | Log each request, when logging is on. |

### `error`

| Key | Type | Default | Notes |
|-----|------|---------|-------|
| `error.enabled` | bool | `true` | Master switch for error reporting. |
| `error.throwOnApiError` | bool | `false` | Throw `ApiException` on a non-2xx. Most applications want this on; the default matches the sibling SDKs. |
| `error.handlers.notice` | list | `["log"]` | Handlers per severity: `log`, `echo`. |
| `error.handlers.warn` | list | `["log"]` | |
| `error.handlers.fatal` | list | `["log"]` | |
| `error.logFilename` | string | `granola-errors.log` | Used when `path.logs` is a directory. |
| `error.triggerPhpErrors` | bool | `false` | Also raise a PHP notice/warning. |

### `devMode`

`false` by default. When on, the SDK warns about enum values it does not recognise and validates `EntityMap::overload()` arguments. Useful while integrating; noise in production.

### `testing`

Only read by `tests/validate`, never by the SDK itself.

| Key | Notes |
|-----|-------|
| `testing.api_key` | Key for the live runner. `GRANOLA_API_KEY` in the environment works too. |
| `testing.webhook_url` | An HTTPS URL you control, required by `--write`. |
| `testing.signing_secret` | Reserved for signature checks against a real endpoint. |

### `classMap`

Maps config keys to the classes implementing them. Point one at your own subclass:

```php
use Jcolombo\GranolaApiPhp\Entity\EntityMap;

EntityMap::overload('note', MyNote::class);
EntityMap::overload('notes', MyNoteCollection::class, 'collection');
```

`Note::list()` and every internal lookup then build your class. `defaultCollection` is the fallback for a resource registered without its own collection.

---

## Recipes

### Minimum viable

```php
Configuration::set('connection.apiKey', getenv('GRANOLA_API_KEY'));
$granola = Granola::connect();
```

### Exceptions, bigger pages, cache on

```php
Configuration::set('connection.apiKey', getenv('GRANOLA_API_KEY'));
Configuration::set('error.throwOnApiError', true);
Configuration::set('pagination.notesPageSize', 30);
Configuration::set('enabled.cache', true);
Configuration::set('path.cache', sys_get_temp_dir());
```

### Several workers sharing one key

```php
// Four parallel workers, so each may use a quarter of the budget.
Configuration::set('rateLimit.perMinute', 75);
Configuration::set('rateLimit.burstLimit', 6);
```

### A file for local development

`config/granolaapi.config.json` (gitignored):

```json
{
    "connection": { "apiKey": "grn_your_key_here" },
    "devMode": true,
    "enabled": { "logging": true },
    "path": { "logs": "/tmp/granola" },
    "error": { "throwOnApiError": true },
    "testing": {
        "api_key": "grn_your_key_here",
        "webhook_url": "https://your-tunnel.example.com/granola-webhooks"
    }
}
```

```php
Configuration::overload(__DIR__ . '/config');
```
