# Webhooks

Granola can notify your application when a note is generated, edited, or shared with you. This guide covers both halves: registering an endpoint, and receiving what it delivers.

**Granola's own documentation:** <https://docs.granola.ai/webhooks>

---

## What this package does, and does not

**It does:** verify Standard Webhooks signatures, and turn a raw POST body into a typed `WebhookEvent` with lazy access to the note behind it.

**It does not:** run your endpoint, route events to handlers, queue work, retry, or remember which deliveries it has seen.

That split is deliberate. Routing and queueing are things your framework already does well, and a dispatcher here would compete with it. Signature verification is the part that is easy to get subtly wrong and catastrophic to get wrong at all — so that is what is centralised.

---

## Events

| Event | Fires when |
|-------|-----------|
| `note.generated` | The first AI summary for a note is generated |
| `note.edited` | A note's summary is edited or regenerated |
| `note.access_granted` | A note is shared with you, directly or through a folder |

Granola adds events over time. See [Unknown event types](#unknown-event-types).

---

## Registering an endpoint

```php
use Jcolombo\GranolaApiPhp\Entity\Resource\WebhookEndpoint;
use Jcolombo\GranolaApiPhp\Enum\WebhookEventType;
use Jcolombo\GranolaApiPhp\Enum\WebhookScope;

$endpoint = WebhookEndpoint::register(
    url: 'https://example.com/granola-webhooks',
    scopes: [WebhookScope::Personal, WebhookScope::Public],
);

$secret = $endpoint->signingSecret();
```

> ## ⚠️ The signing secret is returned exactly once
>
> It is in the create response and nowhere else. There is no endpoint that will tell you again. Persist it before this object goes out of scope, or the endpoint has to be deleted and recreated.
>
> ```php
> $secrets->put($endpoint->id(), $endpoint->signingSecret());
> ```

### Options

```php
WebhookEndpoint::register(
    url: 'https://example.com/granola-webhooks',

    // Required. Which notes to receive events for.
    scopes: [WebhookScope::Personal, WebhookScope::Public],

    // Optional. Omit to subscribe to every event.
    events: [WebhookEventType::NoteGenerated, WebhookEventType::NoteEdited],

    // Optional. Restrict to these folders and all their children.
    folderIds: ['fol_4y6LduVdwSKC27'],
);
```

**Scopes** decide which notes produce events:

| Scope | Covers |
|-------|--------|
| `Personal` | Notes you own, notes shared directly with you, private folders shared with you |
| `Public` | Notes visible to everyone in the workspace |
| `Workspace` | Workspace API keys only, and they must pass **exactly** `[Workspace]` — public workspace notes plus spaces with Granola API access enabled |

Workspace admins can disable scopes for non-admin members. A disabled scope returns `403`.

**Folder IDs** come from `GET /v1/folders`:

```php
$folders = Folder::all();
$engineering = $folders->find('fol_4y6LduVdwSKC27');

WebhookEndpoint::register(
    'https://example.com/granola-webhooks',
    [WebhookScope::Public],
    folderIds: [$engineering->id()],
);
```

The filter applies to every subscribed event, and includes subfolders. Maximum 100 folders.

### Managing endpoints

```php
$endpoints = WebhookEndpoint::all();

$endpoints->enabled();
$endpoints->paused();
$endpoints->subscribedTo(WebhookEventType::NoteGenerated);
$endpoints->findByUrl('https://example.com/granola-webhooks');
```

Updates are partial — `save()` PATCHes only the fields that actually changed:

```php
$endpoint->set('url', 'https://example.com/granola-webhooks-v2');
$endpoint->save();

$endpoint->restrictToFolders(['fol_new'])->save();
$endpoint->restrictToFolders([])->save();               // remove the filter
$endpoint->subscribeTo([WebhookEventType::NoteEdited])->save();

$endpoint->disable();                                    // pause
$endpoint->enable();                                     // resume
$endpoint->delete();
```

**Pausing is not buffering.** A paused endpoint keeps its configuration and signing secret, but events that occur while it is paused are never delivered. Pause for maintenance you can afford to miss; otherwise keep the endpoint up and queue on your side.

### Redacted URLs

An endpoint you did not create comes back with `url` reduced to its origin, because a webhook path can carry credentials:

```php
if ($endpoint->isUrlRedacted()) {
    // $endpoint->get('url') is "https://partner.example.org", not the full path
}
```

Endpoints orphaned by a deleted creator account are returned unredacted, so they can be cleaned up. A workspace-managed endpoint has no `created_by`.

---

## Receiving deliveries

### The payload

```json
{
  "event_id": "evt_01HXYZ",
  "event_type": "note.edited",
  "note_id": "not_1d3tmYTlCICgjy",
  "occurred_at": "2026-08-31T09:15:00Z",
  "data": { "changed_fields": ["summary"] }
}
```

It names a note. It does not carry one — content is a second API call.

### The endpoint

```php
use Jcolombo\GranolaApiPhp\Exception\SignatureVerificationException;
use Jcolombo\GranolaApiPhp\Exception\WebhookPayloadException;
use Jcolombo\GranolaApiPhp\Granola;
use Jcolombo\GranolaApiPhp\Webhook\Webhook;
use Jcolombo\GranolaApiPhp\Webhook\WebhookHeaders;

$rawBody = file_get_contents('php://input');

try {
    $event = Webhook::parse(
        $rawBody,
        WebhookHeaders::fromGlobals(),
        $signingSecret,
        Granola::connect(),
    );
} catch (SignatureVerificationException $e) {
    // Not from Granola, or replayed. Log it and stop — do not parse the body.
    error_log('Rejected Granola webhook: ' . $e->getMessage());
    http_response_code(400);
    exit;
} catch (WebhookPayloadException $e) {
    error_log('Unparseable Granola webhook: ' . $e->getMessage());
    http_response_code(400);
    exit;
}

if ($deliveries->seen($event->eventId)) {
    http_response_code(200);   // a retry of something already handled
    exit;
}
$deliveries->record($event->eventId);

$queue->push(new ProcessGranolaNote($event->noteId, $event->rawType));

http_response_code(200);
```

### The event

```php
$event->eventId;         // unique per delivery; retries reuse it
$event->type;            // WebhookEventType, or null if unrecognised
$event->rawType;         // always the string Granola sent
$event->noteId;
$event->occurredAt;      // DateTimeImmutable, or null
$event->changedFields;   // ['summary'] on note.edited
$event->payload;         // the original array

$event->isGenerated();
$event->isEdited();
$event->isAccessGranted();
$event->is('note.generated');
$event->isUnknownType();
$event->changed('summary');
```

### Reaching the note

Fetching is lazy and memoised — nothing is requested until you ask, and asking repeatedly costs one request:

```php
$note = $event->note();          // GET /v1/notes/{id}
$note = $event->note(true);      // ... with the transcript
$transcript = $event->transcript();
```

Without a connection, `note()` throws `WebhookPayloadException` rather than failing obscurely. Attach one later if you parse before you have one:

```php
$event = Webhook::parse($rawBody, $headers, $secret);   // no connection
// ...
$connected = $event->withConnection(Granola::connect());  // returns a new event
```

If you queue the work — and you should — queue the `noteId`, not the event object, and fetch in the worker.

---

## Verification

### The scheme

```
signed content = "{webhook-id}.{webhook-timestamp}.{raw body}"
key            = base64_decode(signing secret without its "whsec_" prefix)
signature      = base64(HMAC-SHA256(signed content, key))
header         = "v1,{signature}"
```

Three headers arrive with every delivery:

| Header | Contents |
|--------|----------|
| `webhook-id` | The delivery's unique ID — the same value as `event_id` |
| `webhook-timestamp` | Delivery time, Unix seconds |
| `webhook-signature` | One or more space-separated `v1,<base64>` entries |

### Using the verifier directly

```php
use Jcolombo\GranolaApiPhp\Webhook\WebhookVerifier;

$verifier = new WebhookVerifier($signingSecret, toleranceSeconds: 300);

$verifier->verify($rawBody, $headers);            // throws on failure
$verifier->isValid($rawBody, $headers);           // bool

// Or without constructing one:
Webhook::verify($rawBody, $headers, $secret);
Webhook::isValid($rawBody, $headers, $secret);
```

### Raw body — the mistake everyone makes once

The signature covers the exact bytes Granola sent. Decode the JSON and re-encode it and the key order, spacing and unicode escaping will differ, and verification will fail every time.

| Stack | Raw body |
|-------|----------|
| Plain PHP | `file_get_contents('php://input')` |
| Laravel | `$request->getContent()` — **not** `$request->all()` |
| Symfony | `$request->getContent()` |
| Slim / PSR-7 | `(string) $request->getBody()` |

In Laravel, also exclude the route from CSRF and from any middleware that mutates the body.

### Headers, from any stack

```php
WebhookHeaders::fromGlobals();                      // $_SERVER
WebhookHeaders::fromArray(getallheaders());         // Apache
WebhookHeaders::fromArray($request->getHeaders());  // PSR-7, Laravel, Symfony
WebhookHeaders::fromAny($whatever);                 // any of the above
```

Lookup is case-insensitive, so casing differences between servers do not matter.

### Timestamp tolerance

A delivery whose timestamp is more than `webhook.toleranceSeconds` (300) from now is rejected in **both** directions — an old one is a replay, a future one is clock skew. If genuine deliveries start failing this check, the server's clock is wrong; fix NTP rather than widening the window.

### Secret rotation

The verifier accepts multiple space-separated signatures, so a rotation that sends both the old and new signature does not drop deliveries:

```
webhook-signature: v1,<old> v1,<new>
```

Any match passes.

---

## Unknown event types

Granola ships new events. An unrecognised type is **not** an error here:

```php
$event->type;            // null
$event->rawType;         // 'note.archived'
$event->isUnknownType(); // true
$event->noteId;          // still there
$event->payload;         // everything Granola sent
```

Which means a `match` needs a default:

```php
match (true) {
    $event->isGenerated() => $this->onGenerated($event),
    $event->isEdited()    => $this->onEdited($event),
    default               => $this->log->info('Unhandled Granola event', ['type' => $event->rawType]),
};
```

Returning 200 for an event you do not handle is correct — it was delivered successfully; you simply chose to ignore it. Returning an error would earn four days of retries for nothing.

---

## Delivery, retries and deduplication

| Your response | Granola |
|---------------|---------|
| 2xx | Delivered |
| 408, 429, 5xx, timeout, network error | Retried, exponential backoff, up to four days |
| 3xx (not followed), other 4xx | Failed, not retried |

**You have 15 seconds.** Longer counts as a timeout. Verify, record, enqueue, respond — do the fetching and transforming in a worker.

After four days without a success the endpoint is disabled and workspace admins are emailed. Re-enable with `$endpoint->enable()`.

**Deduplicate on `event_id`.** Every retry carries the same one, and a network failure after your handler succeeded looks exactly like a failure. The ID is readable before the body is parsed:

```php
$deliveryId = Webhook::deliveryId($headers);
```

Any store works — a unique column, a Redis `SETNX` with a week's TTL, a cache key. It needs to outlive the four-day retry window.

---

## Testing a receiver

### Signed fixtures, no network

`WebhookVerifier` can sign as well as verify, so a test can build a genuine delivery:

```php
$secret = 'whsec_' . base64_encode('test-secret-value');
$verifier = new WebhookVerifier($secret);

$body = json_encode([
    'event_id' => 'evt_test_01',
    'event_type' => 'note.generated',
    'note_id' => 'not_1d3tmYTlCICgjy',
    'occurred_at' => '2026-08-31T09:15:00Z',
]);

$timestamp = time();
$headers = [
    'webhook-id' => 'evt_test_01',
    'webhook-timestamp' => (string) $timestamp,
    'webhook-signature' => $verifier->signatureHeader('evt_test_01', $timestamp, $body),
];

$response = $this->post('/granola-webhooks', $body, $headers);
```

Assert the negatives too: a tampered body, an expired timestamp, a missing header, and a wrong secret must all be rejected.

### Replaying a stored delivery

```php
$event = Webhook::parseUnverified($storedBody, $granola);
```

Skips verification, so it is right for replaying something you already verified and stored, and wrong for anything arriving over the wire. Anyone who knows your endpoint URL can post an unsigned body.

### Against the real API

```bash
tests/validate --write --verbose
```

Creates a real endpoint against `testing.webhook_url`, pauses it, and deletes it.

---

## Operational checklist

- [ ] Signing secret stored at creation, in a secret store, not in the repository
- [ ] Endpoint is HTTPS and publicly reachable
- [ ] Raw body used for verification
- [ ] Route excluded from CSRF and body-mutating middleware
- [ ] Signature failures answer 400 and never parse the body
- [ ] Deduplication keyed on `event_id`, outliving a four-day retry window
- [ ] Work queued, response inside 15 seconds
- [ ] Unknown event types handled by a `default` branch, answering 200
- [ ] Response cache off, or cleared, in the receiving process
- [ ] Alerting on repeated verification failures — that is either an attack or a rotated secret
