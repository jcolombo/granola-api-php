# API reference

Every public class, method and property in the SDK.

- [Granola](#granola) — connections
- [Configuration](#configuration)
- [Note](#note) · [NoteCollection](#notecollection)
- [TranscriptItem](#transcriptitem) · [TranscriptCollection](#transcriptcollection)
- [Folder](#folder) · [FolderCollection](#foldercollection)
- [AuditEvent](#auditevent) · [AuditEventCollection](#auditeventcollection)
- [WebhookEndpoint](#webhookendpoint) · [WebhookEndpointCollection](#webhookendpointcollection)
- [Webhook](#webhook) · [WebhookVerifier](#webhookverifier) · [WebhookEvent](#webhookevent) · [WebhookHeaders](#webhookheaders)
- [Value objects](#value-objects) · [Enums](#enums) · [Exceptions](#exceptions)
- [Shared behaviour](#shared-behaviour) — every resource and collection
- [Endpoint map](#endpoint-map)

---

## Granola

`Jcolombo\GranolaApiPhp\Granola` — a connection, and the registry of all of them.

| Method | Returns | Notes |
|--------|---------|-------|
| `connect(?string $apiKey = null, ?string $name = null, ?string $url = null)` | `Granola` | Static. Null key uses `connection.apiKey`. Same key returns the same instance. Throws `ConfigurationException` when no key is available. |
| `connection(?string $name = null)` | `Granola` | Static. Named connection, or the default — opening it from config if nothing is connected yet. Throws on an unknown name. |
| `hasConnection(?string $name = null)` | `bool` | Static. |
| `setDefault(string $name)` | `Granola` | Static. Promotes a named connection. |
| `disconnect(?string $nameOrKey = null)` | `void` | Static. One connection, or all of them. |
| `connections()` | `array<string, Granola>` | Static. Keyed by name. |
| `execute(RequestAbstraction $request)` | `RequestResponse` | Cache → rate limit → HTTP → 429 retry → cache store. |
| `getName()` | `string` | The alias, or a fingerprint. |
| `getUrl()` | `string` | |
| `getFingerprint()` | `string` | `GranolaApi-***abcd`. Never the key. |
| `setHttpClient(?ClientInterface $client)` | `self` | For proxies, middleware and test doubles. Auth is applied per request, so an injected client stays authenticated. |

---

## Configuration

`Jcolombo\GranolaApiPhp\Configuration` — see [CONFIGURATION.md](CONFIGURATION.md) for every key.

| Method | Returns | Notes |
|--------|---------|-------|
| `get(string $key, mixed $default = null)` | `mixed` | Dot notation. |
| `set(string $key, mixed $value)` | `void` | |
| `has(string $key)` | `bool` | |
| `all()` | `array` | |
| `load(string $path)` | `void` | Deep-merges a JSON file. Throws `JsonException` on malformed JSON. |
| `overload(string $path)` | `void` | File or directory. Silent when absent. |
| `loadedPaths()` | `list<string>` | Every file merged, defaults first. |
| `reset()` | `void` | Back to packaged defaults. |

---

## Note

`Jcolombo\GranolaApiPhp\Entity\Resource\Note` — read-only. `GET /v1/notes`, `GET /v1/notes/{id}`.

### Methods

| Method | Returns | Notes |
|--------|---------|-------|
| `find(string $id, bool $withTranscript = false, $connection = null)` | `Note` | Static. |
| `list($connection = null)` | `NoteCollection` | Static. |
| `fetch(string $id, bool $withTranscript = false)` | `static` | Recovers from 413 automatically. |
| `refresh(bool $withTranscript = false)` | `static` | Re-reads by current ID. |
| `transcript()` | `TranscriptCollection` | Pre-seeded when the transcript was inlined. |
| `hasInlineTranscript()` | `bool` | |
| `transcriptWasTooLarge()` | `bool` | Granola answered 413. |
| `transcriptText(bool $withSpeakers = true, string $separator = "\n")` | `string` | Inline transcript only. |
| `summary()` | `?string` | Markdown when present, else plain text. |
| `attendeeEmails()` | `list<string>` | |
| `folders()` | `list<Folder>` | |
| `folderIds()` | `list<string>` | |
| `isInFolder(string $folderId)` | `bool` | |
| `owner()` | `?User` | |
| `calendarEvent()` | `?CalendarEvent` | |
| `hasCalendarEvent()` | `bool` | |

### Properties

| Property | Type | In listings? |
|----------|------|--------------|
| `id` | `string` | ✅ |
| `object` | `string` | ✅ |
| `title` | `?string` | ✅ |
| `owner` | `User` | ✅ |
| `created_at` | `DateTimeImmutable` | ✅ |
| `updated_at` | `DateTimeImmutable` | ✅ |
| `web_url` | `string` | detail only |
| `calendar_event` | `?CalendarEvent` | detail only |
| `attendees` | `list<User>` | detail only |
| `folder_membership` | `list<Folder>` | detail only |
| `summary_text` | `string` | detail only |
| `summary_markdown` | `?string` | detail only |
| `transcript` | `?list<TranscriptItem>` | with `include=transcript` |

Listings return the summary shape; the rest arrives with `find()`.

---

## NoteCollection

Cursor-paginated. Granola caps `page_size` at 30.

| Method | Returns |
|--------|---------|
| `createdAfter(string\|DateTimeInterface $when)` | `static` |
| `createdBefore(string\|DateTimeInterface $when)` | `static` |
| `createdBetween($from, $to)` | `static` |
| `updatedAfter(string\|DateTimeInterface $when)` | `static` |
| `inFolder(Folder\|string $folder)` | `static` — includes child folders |

Plus everything in [shared collection behaviour](#collections), with `Note` return types.

---

## TranscriptItem

`GET /v1/notes/{id}/transcript`. No ID — transcript items are ordered, not addressed.

| Method | Returns |
|--------|---------|
| `speaker()` | `?Speaker` |
| `text()` | `string` |
| `startTime()` / `endTime()` | `?DateTimeImmutable` |
| `durationSeconds()` | `?int` |
| `isMe()` | `bool` |
| `toLine(string $separator = ': ')` | `string` — `"Alice Smith: ..."` |
| `__toString()` | `string` — same as `toLine()` |

Properties: `speaker`, `text`, `start_time`, `end_time`.

---

## TranscriptCollection

Reached through `Note::transcript()`. Granola caps `page_size` at 100.

| Method | Returns | Notes |
|--------|---------|-------|
| `forNote(Note\|string $note)` | `static` | Binds the endpoint. |
| `noteId()` | `?string` | |
| `seed(array $items)` | `static` | Pre-fill from an inline transcript; marks the collection complete. |
| `toText(bool $withSpeakers = true, string $separator = "\n")` | `string` | |
| `fromMe()` | `list<TranscriptItem>` | |
| `speakerLabels()` | `list<string>` | |

`find()` and string offsets do not apply — there are no IDs. Fetching without a bound note throws `LogicException`.

---

## Folder

`GET /v1/folders`. There is no "get one folder" endpoint.

| Method | Returns |
|--------|---------|
| `all($connection = null)` | `FolderCollection` — static, fetches every page |
| `list($connection = null)` | `FolderCollection` — static |
| `name()` | `?string` |
| `parentId()` | `?string` |
| `isRoot()` | `bool` |

Properties: `id`, `object`, `name`, `parent_folder_id`.

---

## FolderCollection

Sorted alphabetically by Granola. The hierarchy arrives flat; these rebuild it locally and assume the whole list is loaded.

| Method | Returns |
|--------|---------|
| `roots()` | `list<Folder>` |
| `childrenOf(Folder\|string $parent)` | `list<Folder>` — direct children |
| `descendantsOf(Folder\|string $parent)` | `list<Folder>` — any depth |
| `ancestryOf(Folder\|string $folder)` | `list<Folder>` — root first, inclusive |
| `pathOf(Folder\|string $folder, string $separator = ' / ')` | `string` |
| `tree()` | `list<array{folder: Folder, children: list}>` |

---

## AuditEvent

`GET /v1/audit`. Workspace API key on an Enterprise plan; other keys get 401/403.

| Method | Returns | Notes |
|--------|---------|-------|
| `list($connection = null)` | `AuditEventCollection` | Static. |
| `action()` | `string` | Open set — new actions appear over time. |
| `isAction(string $actionOrPrefix)` | `bool` | Prefix matching stops at the dot. |
| `occurredAt()` | `?DateTimeImmutable` | When it happened. |
| `collectedAt()` | `?DateTimeImmutable` | When Granola recorded it — the cursor's sort key. |
| `actor()` | `array` | Raw actor object. |
| `actorType()` | `string` | `user`, `api_key`, `system`, `anonymous`. |
| `actorEmail()` / `actorUserId()` | `?string` | User actors. |
| `actorApiKeySuffix()` | `?string` | API-key actors. Not secret. |
| `actorLabel()` | `string` | Printable, for any variant. |
| `data()` | `array` | Action-specific. Field names are camelCase. |
| `context()` | `array` | `ip_address`, `user_agent`, `client_version`. |
| `ipAddress()` | `?string` | |

Properties: `id`, `object`, `action`, `occurred_at`, `collected_at`, `actor`, `data`, `context`.

---

## AuditEventCollection

Response key is `events`, not `audit_events`.

| Method | Returns |
|--------|---------|
| `action(string $actionOrPrefix)` | `static` |
| `occurredAfter($when)` / `occurredBefore($when)` | `static` |
| `occurredBetween($from, $to)` | `static` |

Both dates must fall inside Granola's one-year retention window.

---

## WebhookEndpoint

The only writable resource. `/v1/webhook-endpoints`.

| Method | Returns | Notes |
|--------|---------|-------|
| `register(string $url, array $scopes, array $events = [], array $folderIds = [], $connection = null)` | `static` | Static. `POST`. Carries the signing secret. |
| `all($connection = null)` | `WebhookEndpointCollection` | Static. Not paginated. |
| `list($connection = null)` | `WebhookEndpointCollection` | Static. |
| `save()` | `static` | `PATCH`, only changed writable fields. No-op when nothing changed. |
| `delete()` | `bool` | |
| `enable()` / `disable()` | `static` | Resume / pause. |
| `restrictToFolders(array $folderIds)` | `static` | Not saved until `save()`. |
| `subscribeTo(array $events)` | `static` | Not saved until `save()`. |
| `signingSecret()` | `?string` | **Only** on the instance from `register()`. |
| `isEnabled()` | `bool` | |
| `isUrlRedacted()` | `bool` | True when you did not create it. |
| `events()` | `list<WebhookEventType>` | Unrecognised names skipped. |
| `scopes()` | `list<WebhookScope>` | |
| `folderIds()` | `list<string>` | Empty means unrestricted. |
| `isSubscribedTo(WebhookEventType\|string $event)` | `bool` | |
| `createdBy()` | `?User` | Null for workspace-managed endpoints. |

Properties: `id`, `object`, `url`, `url_redacted`, `events`, `folder_ids`, `scopes`, `created_by`, `enabled`, `created_at`.
Writable: `url`, `scopes`, `events`, `folder_ids`, `enabled`.

---

## WebhookEndpointCollection

| Method | Returns |
|--------|---------|
| `enabled()` / `paused()` | `list<WebhookEndpoint>` |
| `subscribedTo(WebhookEventType\|string $event)` | `list<WebhookEndpoint>` |
| `findByUrl(string $url)` | `?WebhookEndpoint` — matches only endpoints you created |

Granola returns the complete set with no `hasMore` or `cursor`, so `fetch()` is always enough.

---

## Webhook

`Jcolombo\GranolaApiPhp\Webhook\Webhook` — the façade. See [WEBHOOKS.md](WEBHOOKS.md).

| Method | Returns | Notes |
|--------|---------|-------|
| `parse(string $rawBody, $headers, ?string $secret = null, ?Granola $connection = null)` | `WebhookEvent` | Verify, then parse. Throws `SignatureVerificationException` / `WebhookPayloadException`. |
| `parseUnverified(string $rawBody, ?Granola $connection = null)` | `WebhookEvent` | **No signature check.** Replay and tests only. |
| `verify(string $rawBody, $headers, ?string $secret = null)` | `void` | Throws on failure. |
| `isValid(string $rawBody, $headers, ?string $secret = null)` | `bool` | |
| `deliveryId($headers)` | `?string` | Readable before parsing — the deduplication key. |

`$headers` accepts an array or a `WebhookHeaders`. `$secret` falls back to `webhook.signingSecret`.

---

## WebhookVerifier

| Method | Returns | Notes |
|--------|---------|-------|
| `__construct(string $signingSecret, int $toleranceSeconds = 300)` | | Throws `SignatureVerificationException` on an unusable secret. |
| `withSecret(?string $secret = null, ?int $tolerance = null)` | `self` | Static. Falls back to configuration. |
| `verify(string $rawBody, $headers)` | `void` | |
| `isValid(string $rawBody, $headers)` | `bool` | |
| `sign(string $id, string\|int $timestamp, string $rawBody)` | `string` | Base64 signature. |
| `signatureHeader(string $id, string\|int $timestamp, string $rawBody)` | `string` | `v1,<sig>`. |

`sign()` and `signatureHeader()` exist so tests and replay tools can build a correctly signed request without reimplementing the scheme.

---

## WebhookEvent

Immutable.

| Property | Type |
|----------|------|
| `eventId` | `string` |
| `type` | `?WebhookEventType` — null when unrecognised |
| `rawType` | `string` |
| `noteId` | `string` |
| `occurredAt` | `?DateTimeImmutable` |
| `changedFields` | `list<string>` |
| `payload` | `array` |

| Method | Returns | Notes |
|--------|---------|-------|
| `note(bool $withTranscript = false)` | `Note` | Lazy, memoised. Throws `WebhookPayloadException` without a connection. |
| `transcript()` | `TranscriptCollection` | |
| `withConnection(Granola $connection)` | `self` | Returns a new instance. |
| `hasConnection()` | `bool` | |
| `is(WebhookEventType\|string $type)` | `bool` | |
| `isGenerated()` / `isEdited()` / `isAccessGranted()` | `bool` | |
| `isUnknownType()` | `bool` | |
| `changed(string $field)` | `bool` | |
| `jsonSerialize()` | `array` | The original payload — right for storage and replay. |

---

## WebhookHeaders

| Method | Returns |
|--------|---------|
| `fromArray(array $headers)` | `self` |
| `fromGlobals(?array $server = null)` | `self` — `$_SERVER`, `HTTP_WEBHOOK_ID` → `webhook-id` |
| `fromAny(mixed $headers)` | `self` — array, `WebhookHeaders`, or an object with `getHeaders()`/`getHeaderLine()` |
| `get(string $name)` | `?string` — case-insensitive |
| `has(string $name)` | `bool` |
| `mustGet(string $name)` | `string` — throws when absent |
| `all()` | `array<string, string>` |

Constants: `WebhookHeaders::ID`, `::TIMESTAMP`, `::SIGNATURE`.

---

## Value objects

All immutable and `JsonSerializable`, with a static `fromArray()`.

### User
`name` (`?string`), `email` (`string`). Methods: `displayName()`, `domain()`, `__toString()`.

### CalendarEvent
`eventTitle`, `invitees` (`list<CalendarInvitee>`), `organiser`, `calendarEventId`, `scheduledStartTime`, `scheduledEndTime`. Methods: `scheduledMinutes()`, `inviteeEmails()`, `isExternal()`.

### CalendarInvitee
`email`. Methods: `domain()`, `__toString()`.

### Speaker
`source` (`SpeakerSource`), `attribution` (`?SpeakerAttribution`), `diarizationLabel`, `name`. Methods: `isMe()`, `label()`, `__toString()`.

Only `source` is guaranteed. `label()` picks the best available: name → diarization label → attribution → source.

---

## Enums

| Enum | Cases |
|------|-------|
| `WebhookEventType` | `NoteGenerated`, `NoteEdited`, `NoteAccessGranted` — plus `all()`, `label()` |
| `WebhookScope` | `Personal`, `Public`, `Workspace` — plus `all()` |
| `SpeakerSource` | `Microphone`, `Speaker` |
| `SpeakerAttribution` | `Me`, `Them` |
| `HttpMethod` | `GET`, `POST`, `PATCH`, `DELETE` |
| `ErrorSeverity` | `NOTICE`, `WARN`, `FATAL` |

---

## Exceptions

All extend `GranolaException`, which extends `RuntimeException`.

| Exception | Thrown when |
|-----------|-------------|
| `ConfigurationException` | No API key, unknown connection name, no signing secret |
| `ApiException` | Non-2xx, and `error.throwOnApiError` is on. Has `statusCode`, `errorCode`, `response`, and `isUnauthorized()` / `isForbidden()` / `isNotFound()` / `isRateLimited()` |
| `TranscriptTooLargeException` | 413 with `notes.autoFallbackLargeTranscript` off |
| `SignatureVerificationException` | Missing header, malformed signature or secret, timestamp out of tolerance, no matching signature |
| `WebhookPayloadException` | Invalid JSON, missing required field, `note()` without a connection |

---

## Shared behaviour

### Resources

Every resource extends `AbstractResource`.

| Method | Returns |
|--------|---------|
| `new($connection = null)` | `static` |
| `make(array $data, $connection = null)` | `static` — hydrated |
| `fromArray(array $data)` | `static` |
| `get(string $property, mixed $default = null)` | `mixed` |
| `set(string $property, mixed $value)` | `static` |
| `__get` / `__set` / `__isset` | |
| `id()` | `?string` |
| `isLoaded()` | `bool` |
| `hydrate(array $data)` | `static` |
| `unmapped()` | `array` — fields the SDK does not model, kept rather than dropped |
| `isDirty(?string $property = null)` | `bool` |
| `getDirty()` | `list<string>` |
| `revert()` | `static` |
| `clear()` | `static` |
| `toArray(bool $includeUnmapped = false)` | `array` |
| `toJson(int $flags = 0)` | `string` |
| `connection()` | `Granola` |
| `setConnection(Granola $connection)` | `static` |
| `lastResponse()` | `?RequestResponse` |
| `succeeded()` | `?bool` |

`create()`, `update()` and `delete()` are **not** here — Granola's API is read-only apart from `WebhookEndpoint`, which defines its own.

### Collections

Every collection extends `AbstractCollection` and implements `Iterator`, `ArrayAccess`, `Countable`, `JsonSerializable`.

| Method | Returns | Notes |
|--------|---------|-------|
| `fetch()` | `static` | One page, replacing what is loaded. |
| `fetchNext()` | `static` | Append the next page. |
| `fetchAll()` | `static` | Every page, all resident. |
| `each()` | `Generator` | Every item, one page resident. |
| `rewindPages()` | `static` | Back to unfetched, filters kept. |
| `pageSize(int $size)` | `static` | |
| `maxPages(?int $pages)` | `static` | Null removes the bound. |
| `withCursor(?string $cursor)` | `static` | Resume a stored cursor. |
| `filter(string $key, mixed $value)` | `static` | Raw query parameter. |
| `hasMore()` | `bool` | |
| `cursor()` | `?string` | |
| `pagesFetched()` | `int` | |
| `all()` | `list` | |
| `first()` / `last()` | resource or null | |
| `find(string $id)` | resource or null | |
| `isEmpty()` | `bool` | |
| `flatten(string $property)` | `list<mixed>` | |
| `toArray()` | `list<array>` | |
| `count()` | `int` | Items **loaded**, not the server-side total. |
| `lastResponse()` / `succeeded()` | | |

Concrete collections narrow the return types, so `Note::list()->first()` is a `Note`.

### RequestResponse

| Member | Notes |
|--------|-------|
| `success`, `body`, `headers`, `responseCode`, `responseReason`, `responseTime`, `request` | Readonly |
| `validBody(string $key, int $minQty = 0)` | |
| `errorCode()` | Granola's machine-readable code |
| `errorMessage()` | Best available human-readable reason |
| `header(string $name)` | Case-insensitive |

---

## Endpoint map

| Granola endpoint | This SDK |
|------------------|----------|
| `GET /v1/notes` | `Note::list()` |
| `GET /v1/notes/{id}` | `Note::find($id)` |
| `GET /v1/notes/{id}?include=transcript` | `Note::find($id, true)` |
| `GET /v1/notes/{id}/transcript` | `$note->transcript()` |
| `GET /v1/folders` | `Folder::all()` / `Folder::list()` |
| `GET /v1/audit` | `AuditEvent::list()` |
| `POST /v1/webhook-endpoints` | `WebhookEndpoint::register()` |
| `GET /v1/webhook-endpoints` | `WebhookEndpoint::all()` |
| `PATCH /v1/webhook-endpoints/{id}` | `$endpoint->save()` |
| `DELETE /v1/webhook-endpoints/{id}` | `$endpoint->delete()` |
