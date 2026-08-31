# Integration recipes

Wiring this SDK into a real application: bootstrapping, receiving webhooks in four stacks, and the sync patterns that come up.

---

## Bootstrapping

Configure once, at boot, before anything touches the SDK.

```php
use Jcolombo\GranolaApiPhp\Configuration;
use Jcolombo\GranolaApiPhp\Granola;

Configuration::set('connection.apiKey', getenv('GRANOLA_API_KEY'));
Configuration::set('error.throwOnApiError', true);
Configuration::set('pagination.notesPageSize', 30);

Granola::connect();
```

Resources resolve the default connection themselves, so nothing downstream needs to hold a `Granola` instance.

### Several keys

A personal key and a workspace key see genuinely different sets of notes; an application may need both.

```php
Granola::connect(getenv('GRANOLA_PERSONAL_KEY'));                 // the default
Granola::connect(getenv('GRANOLA_WORKSPACE_KEY'), 'workspace');

Note::list()->fetch();              // personal
Note::list('workspace')->fetch();   // workspace
```

### Dependency injection

Register the connection rather than calling `connect()` from your services:

```php
$container->singleton(Granola::class, function (): Granola {
    Configuration::overload(config_path('granola'));
    Configuration::set('connection.apiKey', config('services.granola.key'));
    return Granola::connect();
});
```

Then pass it explicitly where you want the dependency visible:

```php
final class MeetingImporter
{
    public function __construct(private readonly Granola $granola) {}

    public function recent(string $since): NoteCollection
    {
        return Note::list($this->granola)->updatedAfter($since)->fetch();
    }
}
```

---

## Receiving webhooks

Every version below does the same four things: **verify the raw body, deduplicate on the delivery ID, enqueue, respond**. See [WEBHOOKS.md](WEBHOOKS.md) for why each matters.

### Plain PHP

```php
<?php
// public/granola-webhooks.php

require __DIR__ . '/../vendor/autoload.php';

use Jcolombo\GranolaApiPhp\Configuration;
use Jcolombo\GranolaApiPhp\Exception\SignatureVerificationException;
use Jcolombo\GranolaApiPhp\Exception\WebhookPayloadException;
use Jcolombo\GranolaApiPhp\Webhook\Webhook;
use Jcolombo\GranolaApiPhp\Webhook\WebhookHeaders;

Configuration::set('connection.apiKey', getenv('GRANOLA_API_KEY'));

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$headers = WebhookHeaders::fromGlobals();

try {
    $event = Webhook::parse($rawBody, $headers, getenv('GRANOLA_WEBHOOK_SECRET'));
} catch (SignatureVerificationException | WebhookPayloadException $e) {
    error_log('Rejected Granola webhook: ' . $e->getMessage());
    http_response_code(400);
    exit;
}

if (!$deliveries->claim($event->eventId)) {
    http_response_code(200);   // already handled; this is a retry
    exit;
}

$queue->push(['note_id' => $event->noteId, 'event' => $event->rawType]);

http_response_code(200);
```

### Laravel

```php
// routes/web.php
Route::post('/granola-webhooks', GranolaWebhookController::class)
    ->withoutMiddleware([VerifyCsrfToken::class]);
```

```php
final class GranolaWebhookController
{
    public function __invoke(Request $request): Response
    {
        try {
            $event = Webhook::parse(
                $request->getContent(),            // RAW — never $request->all()
                $request->headers->all(),
                config('services.granola.webhook_secret'),
                app(Granola::class),
            );
        } catch (SignatureVerificationException | WebhookPayloadException $e) {
            Log::warning('Rejected Granola webhook', ['reason' => $e->getMessage()]);
            return response()->noContent(400);
        }

        // Cache::add is atomic, so a concurrent retry loses the race and is dropped.
        if (!Cache::add("granola:delivery:{$event->eventId}", true, now()->addDays(5))) {
            return response()->noContent(200);
        }

        match (true) {
            $event->isGenerated() => ImportGranolaNote::dispatch($event->noteId),
            $event->isEdited() => RefreshGranolaSummary::dispatch($event->noteId),
            $event->isAccessGranted() => ImportGranolaNote::dispatch($event->noteId),
            default => Log::info('Unhandled Granola event', ['type' => $event->rawType]),
        };

        return response()->noContent(200);
    }
}
```

The deduplication TTL outlives Granola's four-day retry window.

Check that no global middleware rewrites the request body — `TrimStrings` and `ConvertEmptyStringsToNull` operate on input, not the raw content, so they are safe, but a custom body-mutating middleware is not.

### Symfony

```php
#[Route('/granola-webhooks', methods: ['POST'])]
final class GranolaWebhookController extends AbstractController
{
    public function __construct(
        private readonly Granola $granola,
        private readonly CacheItemPoolInterface $cache,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(GRANOLA_WEBHOOK_SECRET)%')]
        private readonly string $signingSecret,
    ) {}

    public function __invoke(Request $request): Response
    {
        try {
            $event = Webhook::parse(
                $request->getContent(),
                $request->headers->all(),
                $this->signingSecret,
                $this->granola,
            );
        } catch (SignatureVerificationException | WebhookPayloadException $e) {
            $this->logger->warning('Rejected Granola webhook', ['reason' => $e->getMessage()]);
            return new Response(status: 400);
        }

        $item = $this->cache->getItem('granola_delivery_' . $event->eventId);
        if ($item->isHit()) {
            return new Response(status: 200);
        }
        $this->cache->save($item->set(true)->expiresAfter(432000));

        $this->bus->dispatch(new ProcessGranolaNote($event->noteId, $event->rawType));

        return new Response(status: 200);
    }
}
```

### Slim / any PSR-7 stack

```php
$app->post('/granola-webhooks', function (
    ServerRequestInterface $request,
    ResponseInterface $response
) use ($granola, $secret, $queue, $deliveries): ResponseInterface {
    try {
        $event = Webhook::parse(
            (string) $request->getBody(),
            $request->getHeaders(),
            $secret,
            $granola,
        );
    } catch (SignatureVerificationException | WebhookPayloadException) {
        return $response->withStatus(400);
    }

    if ($deliveries->claim($event->eventId)) {
        $queue->push($event->noteId);
    }

    return $response->withStatus(200);
});
```

---

## Sync patterns

### Incremental, cursor-resumable

The shape most integrations want: pick up where the last run stopped, survive being killed mid-run, and never re-read the whole archive.

```php
final class GranolaSync
{
    public function __construct(
        private readonly Granola $granola,
        private readonly StateStore $state,
        private readonly NoteRepository $notes,
    ) {}

    public function run(int $budget = 500): void
    {
        $notes = Note::list($this->granola)->pageSize(30);

        // A stored cursor resumes an interrupted run; otherwise start from the
        // last completed sync. Never both — a cursor already encodes its filter.
        $cursor = $this->state->get('granola.cursor');
        if ($cursor !== null) {
            $notes->withCursor($cursor);
        } else {
            $notes->updatedAfter($this->state->get('granola.since') ?? '2020-01-01');
        }

        $startedAt = new DateTimeImmutable();
        $processed = 0;

        do {
            $notes->fetch();

            if ($notes->succeeded() !== true) {
                // Leave the cursor where it is; the next run retries this page.
                throw new RuntimeException('Granola sync failed: ' . $notes->lastResponse()?->errorMessage());
            }

            foreach ($notes as $summary) {
                $this->notes->upsert(Note::find($summary->id(), false, $this->granola));
                $processed++;
            }

            // Commit after every page, so an interruption costs one page at most.
            $this->state->put('granola.cursor', $notes->cursor());
        } while ($notes->hasMore() && $processed < $budget);

        if (!$notes->hasMore()) {
            $this->state->put('granola.cursor', null);
            $this->state->put('granola.since', $startedAt->format(DATE_ATOM));
        }
    }
}
```

Three things this gets right:

- **The cursor is committed per page**, so a crash costs one page, not the run.
- **A failed page does not advance the cursor**, so nothing is silently skipped.
- **`since` only moves when the walk completes**, so a partial run cannot create a gap.

The listing gives summaries; `Note::find()` per note is what costs requests. Budget accordingly — at 300 requests/minute, ~250 notes per minute of detail fetching.

### Backfilling a large archive

```php
foreach (Note::list()->pageSize(30)->each() as $summary) {
    $repository->upsert(Note::find($summary->id()));
}
```

`each()` holds one page at a time, so memory stays flat over any number of notes. For a first import, walk in date windows so a failure resumes at a window boundary:

```php
$window = new DateTimeImmutable('2020-01-01');
$now = new DateTimeImmutable();

while ($window < $now) {
    $next = $window->modify('+1 month');

    foreach (Note::list()->createdBetween($window, $next)->pageSize(30)->each() as $summary) {
        $repository->upsert(Note::find($summary->id()));
    }

    $state->put('backfill.window', $next->format(DATE_ATOM));
    $window = $next;
}
```

### Transcripts into a search index

```php
$note = Note::find($noteId, withTranscript: true);

$index->put($noteId, [
    'title' => $note->title,
    'summary' => $note->summary(),
    'attendees' => $note->attendeeEmails(),
    'occurred_at' => $note->created_at->format(DATE_ATOM),
    'transcript' => $note->transcript()->fetchAll()->toText(),
]);
```

`fetchAll()` leaves the transcript complete however it arrived: it returns immediately when the transcript came inline, and pages the rest when it did not — including after a `413` fallback. No branching, and no request wasted re-fetching content already in hand.

### Folder-scoped processing

```php
$folders = Folder::all();
$clientWork = $folders->find('fol_4y6LduVdwSKC27');

foreach ($folders->descendantsOf($clientWork) as $sub) {
    echo $folders->pathOf($sub), PHP_EOL;
}

// The server-side filter already includes subfolders — no need to iterate them.
foreach (Note::list()->inFolder($clientWork)->each() as $note) {
    // ...
}
```

---

## Production notes

**Store secrets in a secret store.** The API key and each endpoint's signing secret. The bundled `.gitignore` excludes `granolaapi.config.json` so the conventional filename is safe locally, but production belongs in the environment or a vault.

**Turn on exceptions.** `error.throwOnApiError = true` makes a 401 loud instead of an empty collection.

**Never log the key.** `$granola->getFingerprint()` returns `GranolaApi-***abcd`; the SDK logs that and nothing more.

**Divide the rate budget across workers.** The limiter is per process — see [CACHING-AND-RATE-LIMITS.md](CACHING-AND-RATE-LIMITS.md).

**Do not cache in a webhook receiver.** The event says the note just changed; a cached copy is the stale one.

**Watch `unmapped()` in staging.** Fields Granola adds land there rather than being dropped, so logging non-empty `unmapped()` is an early warning that this package has fallen behind their API.

**Alert on verification failures.** A sustained run of them means either an attack or a signing secret that has been rotated out from under you.

**Expect notes to be missing.** Granola only returns notes that already have a generated summary and transcript, so a note created moments ago may 404 or simply not be listed yet. Treat that as "not ready", not as "deleted".
