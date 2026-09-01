# Changelog

All notable changes to `jcolombo/granola-api-php` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-08-31

Initial release. Covers every endpoint documented in Granola's public API.

> **Verified against Granola's published OpenAPI document, not against a live account.** The test fixtures are transcribed from that document and the suite covers every internal behaviour, but no request in this release has been made against the real API. Run `tests/validate` with a real key before relying on it in production, and pin to `^0.1`.

### Added

**Connections**
- `Granola::connect()` with a configured default API key, plus any number of named additional keys held side by side
- `Granola::connection($name)`, `setDefault()`, `disconnect()` and `connections()`
- `getFingerprint()` for logging a connection without exposing its key
- `setHttpClient()` for proxies, middleware and test doubles, with authentication applied per request so an injected client cannot silently lose it

**Reading**
- `Note` — `find()` with optional inline transcript, `list()` with `createdAfter`/`createdBefore`/`updatedAfter`/`inFolder` filters, and helpers for attendees, folders, summary and calendar event
- `TranscriptItem` and `TranscriptCollection` — paged transcripts, `toLine()`, `toText()`, `fromMe()`, `speakerLabels()`
- `Folder` and `FolderCollection` — `all()`, plus local `roots()`, `childrenOf()`, `descendantsOf()`, `ancestryOf()`, `pathOf()` and `tree()` over Granola's flat listing
- `AuditEvent` — open-set `action` matching with `isAction()`, all four actor variants, and `actorLabel()`
- Value objects for `User`, `CalendarEvent`, `CalendarInvitee` and `Speaker`, with enums for speaker source, speaker attribution, webhook scope and webhook event type

**Pagination**
- Cursor-driven `fetch()`, `fetchNext()`, `fetchAll()` and a memory-constant `each()` generator
- `fetchAll()` completes a collection whatever state it is in: it returns immediately when everything is already loaded, resumes from where a previous `fetch()` stopped rather than starting over, and never re-requests a page it holds. It is idempotent; `rewindPages()` is the explicit reset for re-querying.
- `each()` yields already-loaded items before paging on, so it neither repeats nor skips — and makes no request at all for a transcript that arrived inline
- Together these mean one call returns a complete transcript however it arrived — inline, paged, or after a `413` fallback — with no branching by the caller
- Paging is driven by `hasMore`, never by counting results: Granola documents that a page may be short and still not be the last one
- Public `cursor()` and `hasMore()` so a sync can stop after any page and resume later
- `maxPages` bound on `fetchAll()` and `each()`, with `hasMore()`/`cursor()` left set when it truncates, so the limit is detectable and resumable rather than silent
- Typed accessors on every concrete collection

**Webhook endpoints**
- `WebhookEndpoint::register()` returning the signing secret Granola shows only once
- `save()` PATCHing only changed writable fields, plus `enable()`, `disable()`, `restrictToFolders()`, `subscribeTo()` and `delete()`
- `WebhookEndpointCollection` helpers: `enabled()`, `paused()`, `subscribedTo()`, `findByUrl()`

**Webhook receiving**
- `Webhook::parse()` — Standard Webhooks verification followed by typed parsing
- `WebhookVerifier` — HMAC-SHA256 over `{id}.{timestamp}.{body}`, constant-time comparison, multiple signatures accepted during a secret rotation, configurable timestamp tolerance
- `WebhookEvent` — typed fields, lazy and memoised `note()`/`transcript()`, `withConnection()`, and forward-compatible handling of unknown event types
- `WebhookHeaders` — reads `$_SERVER`, `getallheaders()`, PSR-7 header arrays or any object exposing `getHeaders()`, with no PSR-7 dependency
- `Webhook::deliveryId()` for deduplicating retries before parsing
- `Webhook::parseUnverified()` for replay and tests

**Infrastructure**
- JSON configuration with deep-merged overrides via `Configuration::load()` / `overload()`
- Dual-window rate limiter matched to Granola's published budget, with `Retry-After`-aware 429 retries
- Optional file-backed response cache, replaceable with `Cache::registerCacheMethods()`
- Optional request logging, replaceable with `Log::registerWriter()`
- `error.throwOnApiError` to choose between exceptions and inspectable failed responses
- Automatic recovery from `413 TRANSCRIPT_TOO_LARGE` on Get Note
- Unmodelled response fields preserved in `unmapped()` rather than dropped

### Documentation
- README, plus `docs/API-REFERENCE.md`, `docs/WEBHOOKS.md`, `docs/CONFIGURATION.md`, `docs/CACHING-AND-RATE-LIMITS.md` and `docs/INTEGRATION.md`
- Eight runnable examples
- `OVERRIDES.md` recording every deliberate divergence from the sibling SDK family

### Testing
- PHPUnit suite over fixtures derived from Granola's published OpenAPI document — no API key or network required
- Zero-dependency live runner (`tests/validate`) for checking the real API still sends the documented shapes

[Unreleased]: https://github.com/jcolombo/granola-api-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/jcolombo/granola-api-php/releases/tag/v0.1.0
