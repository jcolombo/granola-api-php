# Granola API PHP SDK — guide for AI agents

A standalone PHP SDK for the Granola meeting-notes API. **Zero ties to any host application or framework** — it must stay usable by any PHP 8.1+ project.

---

## Read these first

| File | When |
|------|------|
| `OVERRIDES.md` | Before changing architecture. Every deliberate divergence from the sibling SDKs, with its reasoning. |
| `docs/API-REFERENCE.md` | Before adding or changing a public method. |
| `docs/WEBHOOKS.md` | Before touching anything under `src/Webhook/`. |
| `CONTRIBUTING.md` | Conventions, and the checklist for adding an endpoint. |

---

## What Granola's API actually allows

Get this wrong and you will design something that cannot work:

- **Read-only, except webhook endpoints.** No endpoint creates or edits a note. Do not add `create()`/`update()`/`delete()` to `AbstractResource`.
- **Cursor pagination**, never page numbers. A page may hold fewer items than `page_size` and still not be the last one — Granola says so explicitly. Page on `hasMore`, never on counting results.
- **No "get one folder" endpoint.** Folders are listed and indexed locally.
- **Webhook payloads name a note; they do not carry it.** Content is a second call.
- **Only notes with a generated AI summary and transcript are returned.** A missing note may simply not be ready.
- **`413 TRANSCRIPT_TOO_LARGE`** is an expected answer on Get Note, not a failure.
- **Audit `action` is an open set** of strings Granola adds to. Never model it as an enum.

Source of truth: <https://docs.granola.ai/api-reference/openapi.json>. Read it before writing anything that touches a shape.

---

## Layout

```
src/
  Granola.php              Connection registry + request execution
  Configuration.php        Dot-notation config over default.granolaapi.config.json
  Request.php              Path/query builder
  Entity/
    AbstractEntity         Connection resolution, lastResponse()
    AbstractResource       Hydration, typed props, dirty tracking — NO CRUD
    AbstractCollection     Cursor pagination: fetch/fetchNext/fetchAll/each
    EntityMap              classMap lookups, overload()
    Resource/              Note, Folder, TranscriptItem, AuditEvent, WebhookEndpoint
    Collection/            One per resource, with narrowed return types
    Value/                 User, CalendarEvent, CalendarInvitee, Speaker
  Enum/                    WebhookEventType, WebhookScope, SpeakerSource, SpeakerAttribution
  Exception/               All extend GranolaException
  Utility/                 Converter, Error, Log, RateLimiter, Request*/Response
  Cache/                   Optional file cache, replaceable backend
  Webhook/                 Webhook, WebhookVerifier, WebhookEvent, WebhookHeaders
```

---

## Rules

1. **No framework, no host-application coupling.** No PSR-7 dependency, no container, no service providers, no references to any specific application. `WebhookHeaders::fromAny()` is the pattern for interoperating without depending.
2. **No new runtime dependencies.** Guzzle and `adbario/php-dot-notation` are the entire list. Optional integrations go behind a callback the host registers (`Cache::registerCacheMethods()`, `Log::registerWriter()`).
3. **The webhook subsystem verifies and parses. It does not dispatch.** No listener registry, no handler auto-discovery, no deduplication store. Those are the host application's job, and this was an explicit design decision — see OVERRIDE-009.
4. **Never break forward compatibility.** Unknown response fields go to `unmapped()`. Unknown webhook event types parse with `type === null` and `rawType` set. Do not start throwing on either.
5. **Never log or return an API key.** `getFingerprint()` exists for this.
6. **Verify webhooks against the raw body.** Any change that decodes before verifying is a security bug.
7. **PHP 8.1 is the floor.** No 8.2+ syntax (no readonly classes, no DNF types).
8. **Record divergences** in `OVERRIDES.md` with an `@override OVERRIDE-NNN` annotation in the code.

---

## Testing

```bash
composer test           # PHPUnit over fixtures — no key, no network. Must stay green.
tests/validate          # live checks against the real API (needs a key)
tests/validate --write  # also creates and deletes one webhook endpoint
```

Where no host PHP is available, run inside a container that has one:

```bash
docker exec -w /var/www/packages/granola-api-php account_manager vendor/bin/phpunit
```

Add a test for every behaviour change. Fixtures live in `tests/Fixtures/` and come from the OpenAPI examples — if you change one, change it to match Granola, not to match the code.

---

## Common tasks

**Adding an endpoint** — the eight-step checklist is in `CONTRIBUTING.md`. Do not skip registering the class in `classMap.entity`, or `Note::list()`-style lookups will not find it.

**Changing pagination** — `AbstractCollection::loadPage()` is the only place that reads `hasMore`/`cursor`. `each()` deliberately clears `$this->data` per page to hold memory flat; do not "fix" that into accumulation.

**Touching verification** — `WebhookVerifier` is the highest-risk file in the package. Keep `hash_equals()`, keep multi-signature support (secret rotation), keep two-directional timestamp tolerance, and keep signing available so tests can build genuine deliveries.

**Adding config** — add the key to `default.granolaapi.config.json` **and** to the table in `docs/CONFIGURATION.md`. An undocumented key is an invisible key.
