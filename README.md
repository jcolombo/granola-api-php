# Granola API for PHP

A PHP SDK for the [Granola](https://granola.ai) meeting-notes API, with a framework-agnostic webhook parser and signature verifier.

[![Latest Version](https://img.shields.io/packagist/v/jcolombo/granola-api-php.svg)](https://packagist.org/packages/jcolombo/granola-api-php)
[![PHP Version](https://img.shields.io/packagist/php-v/jcolombo/granola-api-php.svg)](https://packagist.org/packages/jcolombo/granola-api-php)
[![License](https://img.shields.io/github/license/jcolombo/granola-api-php)](LICENSE)
[![GitHub Issues](https://img.shields.io/github/issues/jcolombo/granola-api-php)](https://github.com/jcolombo/granola-api-php/issues)

---

## Overview

An independently developed PHP toolkit for reading Granola meeting notes, transcripts and folders, managing webhook endpoints, and turning inbound webhook deliveries into typed PHP objects. Not affiliated with or endorsed by Granola.

**Granola API documentation:** <https://docs.granola.ai/introduction>

> **Stability notice:** this package is in active development (v0.1.x). The API surface may change before v1.0. Pin to `^0.1` in production.

### What Granola's API can and cannot do

Worth knowing before you design around it:

- **It is read-only, apart from webhook endpoints.** You can read notes, transcripts, folders and audit events. There is no endpoint that creates or edits a note.
- **It only returns notes that already have a generated AI summary and transcript.** A note visible in the desktop app may not be listable yet.
- **There is no "get one folder" endpoint.** Folders are listed, then indexed locally.
- **Webhook payloads name a note; they do not carry it.** Content requires a follow-up API call.
- **Personal and workspace API keys need a Business or Enterprise plan.**

---

## Features

- **Every documented endpoint** — list/get notes, paged transcripts, folders, audit events, and full CRUD on webhook endpoints
- **Cursor pagination done properly** — `fetch()` for one page, `fetchAll()` for all of them, `each()` to stream a large archive at constant memory, and a cursor you can persist between cron runs
- **A webhook toolkit, not a framework** — Standard Webhooks HMAC verification and typed parsing; your application keeps its own endpoint, routing and queueing
- **Lazy note hydration** — a webhook event fetches its note only if a handler actually asks for one
- **Multiple API keys** — one configured default plus any number of named overrides, side by side
- **Typed objects throughout** — `DateTimeImmutable` timestamps, enums for speakers, scopes and event types, and value objects for users and calendar events
- **Forward-compatible** — unknown response fields are kept, not dropped; unknown webhook event types parse instead of throwing
- **Transparent 413 handling** — a transcript too large to inline falls back to the paged endpoint on its own
- **Rate limiting** — a client-side dual-window limiter matched to Granola's published budget, with `Retry-After`-aware 429 retries
- **Optional caching and logging** — off by default, file-backed or delegated to your own stack
- **No framework dependencies** — Guzzle and a dot-notation config helper, nothing else

---

## Requirements

- PHP 8.1 or higher
- A Granola account on a **Business or Enterprise** plan, with API access
- A Granola API key (`grn_…`)
- Composer

---

## Installation

```bash
composer require jcolombo/granola-api-php
```

### Getting an API key

In the Granola desktop app: **Settings → Connectors → API keys → Create new key**, then choose the scopes it should carry.

| Scope | What it reaches |
|-------|-----------------|
| **Personal** | Notes you own, notes shared directly with you, and private folders shared with you |
| **Public** | Notes visible to everyone in the workspace, including team space notes |
| **Workspace** | Admin-created keys only: public workspace notes plus spaces with Granola API access enabled. These keys do not expire. |

Keys are revoked from the same screen, and revocation is permanent.

---

## Quick start

```php
use Jcolombo\GranolaApiPhp\Configuration;
use Jcolombo\GranolaApiPhp\Granola;
use Jcolombo\GranolaApiPhp\Entity\Resource\Note;

Configuration::set('connection.apiKey', getenv('GRANOLA_API_KEY'));

$granola = Granola::connect();

foreach (Note::list()->pageSize(30)->fetch() as $note) {
    echo $note->title, ' — ', $note->created_at->format('Y-m-d'), PHP_EOL;
}
```

Every resource resolves the default connection on its own, so `Note::list()` needs no arguments once `connect()` has been called.

---

## Connecting

### The default key

Put the key in configuration and connect with no arguments:

```php
Configuration::set('connection.apiKey', getenv('GRANOLA_API_KEY'));
// or load it from a file: Configuration::overload(__DIR__ . '/config');

$granola = Granola::connect();
```

### Additional keys

Extra keys live alongside the default and are addressed by name:

```php
$workspace = Granola::connect($workspaceKey, 'workspace');

// Anywhere later, without passing the key around:
$folders = Folder::all('workspace');
$notes   = Note::list('workspace')->fetch();

// Or fetch the connection itself:
$same = Granola::connection('workspace');
```

Connections are keyed by API key, so connecting twice with the same key returns the same instance. The first connection made becomes the default; `Granola::setDefault('workspace')` moves it.

---

## Reading notes

### One note

```php
$note = Note::find('not_1d3tmYTlCICgjy');

echo $note->title;
echo $note->summary();              // markdown when present, else plain text
echo $note->summary_markdown;
echo $note->web_url;

$note->created_at;                  // DateTimeImmutable
$note->owner()->email;              // User value object
$note->attendeeEmails();            // ['oat@granola.ai', ...]
$note->folderIds();                 // ['fol_4y6LduVdwSKC27']
$note->isInFolder('fol_4y6LduVdwSKC27');
```

### Listing and filtering

```php
$notes = Note::list()
    ->updatedAfter('2026-08-01')                  // string or DateTimeInterface
    ->createdBefore(new DateTimeImmutable('now'))
    ->inFolder('fol_4y6LduVdwSKC27')              // includes child folders
    ->pageSize(30)                                // Granola caps this at 30
    ->fetch();
```

A listing returns the **summary** shape only — `id`, `object`, `title`, `owner`, `created_at`, `updated_at`. Summaries, attendees, calendar event and transcript arrive with `Note::find()`, so walking a list is cheap and you pay for detail only on the notes you actually want.

### The calendar event

```php
$event = $note->calendarEvent();     // null for ad-hoc recordings

$event?->eventTitle;
$event?->scheduledStartTime;         // DateTimeImmutable
$event?->scheduledMinutes();         // 60
$event?->inviteeEmails();
$event?->isExternal();               // true when an invitee is outside the organiser's domain
```

---

## Pagination

Granola pages with an opaque cursor and a `hasMore` flag. It also warns that **a page can hold fewer items than you asked for and still not be the last one** — so never page by counting results. This SDK always drives paging from `hasMore`.

```php
$notes = Note::list();

$notes->fetch();        // one page
$notes->fetchNext();    // append the next page
$notes->fetchAll();     // every page, all resident in memory

foreach ($notes->each() as $note) {   // every page, one page resident
    // ...
}
```

`fetchAll()` is the "give me the complete set, stitched together" call — no manual paging loop. It is safe to call at any point: it resumes from wherever the collection stopped, returns immediately when everything is already loaded, and never re-requests a page it holds. To deliberately re-query with different filters, reset first with `rewindPages()`.

`each()` is the streaming equivalent, for sets large enough that holding them all is the wrong shape. It yields anything already loaded before paging on, so it too can be called on a partly-fetched collection without skipping or repeating items.

Both are bounded by `maxPages` (1000 by default, `->maxPages(50)` to change, `->maxPages(null)` to remove). If the bound truncates a walk, `hasMore()` stays `true` and `cursor()` is non-null — so truncation is detectable and resumable, never silent.

### Resuming later

The cursor is public state, so a long-running sync can stop after any page and pick up where it left off:

```php
$page = Note::list()->updatedAfter($lastSync)->fetch();
$cursor = $page->cursor();
$store->save('granola_cursor', $cursor);

// ... the next cron run ...
Note::list()->withCursor($store->get('granola_cursor'))->fetch();
```

### Collection access

```php
count($notes);                       // items loaded, not the server-side total
$notes->first();  $notes->last();
$notes->find('not_1d3tmYTlCICgjy');  // by id
$notes['not_1d3tmYTlCICgjy'];        // same, via ArrayAccess
$notes->flatten('title');            // one property from every item
$notes->toArray();
json_encode($notes);
```

---

## Transcripts

```php
$transcript = $note->transcript();

foreach ($transcript->each() as $item) {
    echo $item->toLine(), PHP_EOL;          // "Alice Smith: we should ship on Friday"
    $item->text();
    $item->speaker()?->label();
    $item->speaker()?->isMe();
    $item->startTime();                      // DateTimeImmutable
    $item->durationSeconds();
}

echo $transcript->toText();                  // whole thing as text
$transcript->fromMe();                       // only the note owner's lines
$transcript->speakerLabels();
```

### Inline vs paged, and the 413

Asking for the transcript with the note is one request instead of two:

```php
$note = Note::find('not_1d3tmYTlCICgjy', withTranscript: true);
$note->hasInlineTranscript();     // true
$note->transcript();              // no extra request — already loaded
```

For long meetings Granola answers **413 `TRANSCRIPT_TOO_LARGE`** instead. The SDK handles it: the note is re-fetched without the transcript, `transcriptWasTooLarge()` becomes true, and `transcript()` pages from `/v1/notes/{id}/transcript` instead.

You never have to branch on which happened. Both of these do the right thing either way:

```php
// Complete transcript, stitched together and held in memory.
$text = Note::find($id, true)->transcript()->fetchAll()->toText();

// Same coverage, streamed one page at a time.
foreach (Note::find($id, true)->transcript()->each() as $item) { ... }
```

When the transcript arrived inline, both return what is already loaded and make **no** further request. When it did not, they page until the transcript is complete.

Set `notes.autoFallbackLargeTranscript` to `false` to get a `TranscriptTooLargeException` and handle it yourself.

### Speaker attribution

`speaker.source` is the only guaranteed field.

- **macOS** transcripts carry `attribution` — `me` (the note's owner) or `them`.
- **iOS** captures a single audio stream, so `source` is always `microphone`, `attribution` may be absent, and an anonymous `diarization_label` (`Speaker A`) may appear instead.
- `name` appears only when Granola resolved the speaker to a person.

`$speaker->label()` picks the best available: name, then diarization label, then attribution, then source.

---

## Folders

Granola has no "get one folder" endpoint, and returns the hierarchy flat as a `parent_folder_id` on each folder. Fetch them all once and index locally:

```php
$folders = Folder::all();

$folders->find('fol_4y6LduVdwSKC27')->name();
$folders->roots();
$folders->childrenOf('fol_a74g2hvl98iUHG');
$folders->descendantsOf('fol_a74g2hvl98iUHG');   // any depth
$folders->pathOf('fol_9m2QpRsTuVwX10');          // "Product / Top secret recipes / Greek"
$folders->tree();                                 // nested ['folder' => Folder, 'children' => [...]]
```

Folder IDs are what restrict a webhook endpoint's deliveries and filter `Note::list()`, and both include child folders automatically.

---

## Audit events

Requires a workspace API key on an Enterprise plan.

```php
foreach (AuditEvent::list()->action('workspace')->occurredAfter('2026-08-01')->each() as $event) {
    echo $event->action(), ' by ', $event->actorLabel(), PHP_EOL;
}
```

- `action` is an **open set** of dot-separated strings Granola adds to over time, so it is not an enum here. `isAction('workspace')` matches `workspace.member_added` but not `workspace_automation.created` — prefix matching stops at the dot.
- Events are ordered by `collected_at`, not `occurred_at`. Granola learns about some events after the fact, so `collected_at` is the field that never moves under a cursor.
- `actor` is one of four shapes — `user`, `api_key`, `system`, `anonymous`. `actorLabel()` renders any of them; `actorEmail()`, `actorUserId()` and `actorApiKeySuffix()` reach the specifics.
- Both date filters must fall inside Granola's one-year retention window.

---

## Webhooks

Two separate jobs, covered separately: **registering** an endpoint with Granola, and **receiving** what it delivers.

### Registering an endpoint

```php
use Jcolombo\GranolaApiPhp\Entity\Resource\WebhookEndpoint;
use Jcolombo\GranolaApiPhp\Enum\WebhookScope;
use Jcolombo\GranolaApiPhp\Enum\WebhookEventType;

$endpoint = WebhookEndpoint::register(
    url: 'https://example.com/granola-webhooks',
    scopes: [WebhookScope::Personal, WebhookScope::Public],
    events: [WebhookEventType::NoteGenerated, WebhookEventType::NoteEdited],  // omit for all
    folderIds: ['fol_4y6LduVdwSKC27'],                                        // omit for everything
);

$secret = $endpoint->signingSecret();   // ⚠️ shown exactly once, right here
```

> **Store the signing secret immediately.** Granola returns it in the create response and never again. Lose it and the endpoint has to be recreated.

Managing endpoints:

```php
$endpoints = WebhookEndpoint::all();
$endpoints->enabled();  $endpoints->paused();
$endpoints->subscribedTo(WebhookEventType::NoteGenerated);

$endpoint->disable();                                 // pause deliveries
$endpoint->enable();
$endpoint->restrictToFolders(['fol_…'])->save();      // PATCHes only what changed
$endpoint->delete();
```

A paused endpoint keeps its configuration and secret, but events that occur while it is paused are **not** delivered later.

Endpoints you did not create come back with `url` reduced to its origin (`url_redacted === true`), because a webhook path can carry credentials.

### Receiving a delivery

This package does not run your endpoint and does not dispatch to listeners — routing, queueing, retries and deduplication stay in your application. What it removes is the part every integration would otherwise reimplement: getting the signature check exactly right, and parsing the payload into something typed.

```php
use Jcolombo\GranolaApiPhp\Webhook\Webhook;
use Jcolombo\GranolaApiPhp\Webhook\WebhookHeaders;

$rawBody = file_get_contents('php://input');   // the RAW bytes — see below

try {
    $event = Webhook::parse($rawBody, WebhookHeaders::fromGlobals(), $secret, Granola::connect());
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

$event->eventId;         // unique per delivery — your deduplication key
$event->type;            // WebhookEventType enum, or null for a type we don't know
$event->noteId;
$event->occurredAt;      // DateTimeImmutable
$event->changedFields;   // ['summary'] on note.edited
$event->payload;         // the original array, untouched

$note = $event->note();          // one GET, memoised, only if you ask
$note = $event->note(true);      // with the transcript
$event->transcript();
```

Then hand it to your own code:

```php
match (true) {
    $event->isGenerated()     => $queue->push(new IndexNote($event->noteId)),
    $event->isEdited()        => $queue->push(new RefreshSummary($event->noteId)),
    $event->isAccessGranted() => $queue->push(new GrantAccess($event->noteId)),
    default                   => null,   // an event type this SDK version predates
};

http_response_code(200);
```

`WebhookHeaders` accepts whatever your stack produces — `fromGlobals()`, `fromArray(getallheaders())`, `fromArray($request->getHeaders())` for PSR-7/Laravel/Symfony, or `fromAny($whatever)`.

### Five things that will bite you

1. **Verify the raw body.** The signature covers the exact bytes Granola sent. A framework that hands you a decoded array has already destroyed the key order and whitespace, and re-encoding it will never match. In Laravel that means `$request->getContent()`, not `$request->all()`.
2. **Answer within 15 seconds.** Granola retries on timeouts and 5xx with exponential backoff for four days, then disables the endpoint and emails workspace admins. Queue the work; respond immediately.
3. **Deduplicate on `event_id`.** Every retry of a delivery carries the same one. `Webhook::deliveryId($headers)` reads it before the body is even parsed.
4. **Expect events you don't recognise.** Granola adds them. An unknown type parses with `type === null` and `rawType` set, rather than throwing — do not `match` on it without a `default`.
5. **The payload has no note content.** It names a note; fetching it is a second call against your rate limit.

Which responses count as what:

| Your response | Granola's behaviour |
|---------------|---------------------|
| 2xx | Delivered |
| 408, 429, 5xx, timeout, network error | Retried with backoff for four days |
| 3xx (redirects are not followed), other 4xx | Failed, not retried |

### Signature scheme

Standard Webhooks, verified against three headers — `webhook-id`, `webhook-timestamp`, `webhook-signature` (`v1,<base64>`):

```
signature = base64(HMAC-SHA256("{webhook-id}.{webhook-timestamp}.{raw body}", key))
key       = base64_decode(signing secret without its "whsec_" prefix)
```

The verifier compares in constant time, accepts several space-separated signatures (so a secret rotation does not drop deliveries), and rejects a timestamp more than `webhook.toleranceSeconds` (300 by default) away from now in either direction.

`Webhook::parseUnverified()` exists for replaying stored deliveries and for tests. Never point it at live traffic.

---

## Configuration

Defaults ship in `default.granolaapi.config.json`. Layer your own on top — each load deep-merges, so an override only needs the keys it changes.

```php
Configuration::overload(__DIR__ . '/config');            // finds granolaapi.config.json
Configuration::load('/etc/myapp/granola.json');          // or an explicit file
Configuration::set('connection.apiKey', $key);           // or set values directly
```

The keys you are most likely to touch:

| Key | Default | What it does |
|-----|---------|--------------|
| `connection.apiKey` | `null` | The default API key |
| `connection.timeout` | `30` | HTTP timeout, seconds |
| `webhook.signingSecret` | `null` | Default secret for `Webhook::parse()` |
| `webhook.toleranceSeconds` | `300` | Accepted delivery-timestamp drift |
| `error.throwOnApiError` | `false` | Throw `ApiException` on a non-2xx instead of logging |
| `notes.autoFallbackLargeTranscript` | `true` | Recover from a 413 automatically |
| `enabled.cache` | `false` | Cache GET responses |
| `enabled.logging` | `false` | Write a request log |
| `devMode` | `false` | Warn about unexpected enum values |

Full reference: [docs/CONFIGURATION.md](docs/CONFIGURATION.md).

---

## Error handling

By default a non-2xx is **logged, not thrown** — matching the sibling SDKs in this family. The call returns an unpopulated object you can interrogate:

```php
$note = Note::find('not_doesNotExist');

if ($note->succeeded() !== true) {
    $note->lastResponse()->responseCode;    // 404
    $note->lastResponse()->errorMessage();
    $note->lastResponse()->errorCode();     // Granola's machine-readable code
}
```

Most applications will prefer exceptions:

```php
Configuration::set('error.throwOnApiError', true);

try {
    $note = Note::find($id);
} catch (ApiException $e) {
    $e->isUnauthorized();   // 401 — bad or revoked key
    $e->isForbidden();      // 403 — scope disabled by workspace API access controls
    $e->isNotFound();       // 404
    $e->isRateLimited();    // 429
}
```

Every exception extends `GranolaException`.

---

## Rate limits

Granola allows a **25-request burst over any 5 seconds** and **5 requests/second sustained (300/minute)**. The SDK tracks both windows locally and waits rather than earning a 429. If one arrives anyway, it honours `Retry-After` when present and otherwise backs off exponentially, up to `rateLimit.maxRetries`.

The limiter is per API key and per PHP process — it does not coordinate across workers. If you run parallel jobs against one key, lower `rateLimit.perMinute` proportionally.

See [docs/CACHING-AND-RATE-LIMITS.md](docs/CACHING-AND-RATE-LIMITS.md).

---

## Documentation

| Document | Contents |
|----------|----------|
| [docs/API-REFERENCE.md](docs/API-REFERENCE.md) | Every class, method and property |
| [docs/WEBHOOKS.md](docs/WEBHOOKS.md) | Full webhook guide: registration, receiving, verification, operations |
| [docs/CONFIGURATION.md](docs/CONFIGURATION.md) | Every configuration key |
| [docs/CACHING-AND-RATE-LIMITS.md](docs/CACHING-AND-RATE-LIMITS.md) | Caching, custom backends, limiter behaviour |
| [docs/INTEGRATION.md](docs/INTEGRATION.md) | Recipes for plain PHP, Laravel, Symfony, Slim; sync patterns |
| [examples/](examples/) | Eight runnable scripts |
| [OVERRIDES.md](OVERRIDES.md) | Design decisions, and why each was made |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Development setup and testing |

---

## Testing

```bash
composer test           # PHPUnit — fixtures, no key or network needed
composer test:live      # live checks against the real API
```

The PHPUnit suite runs against fixtures derived from Granola's published OpenAPI document, so a change on their side surfaces as a failing test. The live runner needs no test framework and checks that those documented shapes are still what Granola actually sends.

---

## License

MIT — see [LICENSE](LICENSE).

This is an independent project. Granola is a trademark of its respective owner; this package is not affiliated with or endorsed by Granola.
