<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Tests\Live;

use Jcolombo\GranolaApiPhp\Configuration;
use Jcolombo\GranolaApiPhp\Entity\Resource\AuditEvent;
use Jcolombo\GranolaApiPhp\Entity\Resource\Folder;
use Jcolombo\GranolaApiPhp\Entity\Resource\Note;
use Jcolombo\GranolaApiPhp\Entity\Resource\WebhookEndpoint;
use Jcolombo\GranolaApiPhp\Granola;
use Jcolombo\GranolaApiPhp\Webhook\Webhook;
use Jcolombo\GranolaApiPhp\Webhook\WebhookHeaders;
use Jcolombo\GranolaApiPhp\Webhook\WebhookVerifier;

/**
 * End-to-end checks against the real Granola API, with no test framework.
 *
 * The PHPUnit suite proves the SDK parses Granola's documented shapes; this
 * proves those shapes are still what Granola actually sends. Run it after a
 * Granola API changelog entry, before a release, and when an integration starts
 * behaving oddly.
 *
 * Reads its key from `testing.api_key` in a granolaapi.config.json override, or
 * from the GRANOLA_API_KEY environment variable.
 *
 * Read-only by default. `--write` additionally creates, pauses and deletes one
 * webhook endpoint, which is the only mutation Granola's API allows.
 */
final class LiveRunner
{
    private const GREEN = "\033[0;32m";
    private const RED = "\033[0;31m";
    private const YELLOW = "\033[1;33m";
    private const DIM = "\033[2m";
    private const RESET = "\033[0m";

    private bool $dryRun = false;

    private bool $verbose = false;

    private bool $allowWrites = false;

    private int $passed = 0;

    private int $failed = 0;

    private int $skipped = 0;

    private ?Granola $granola = null;

    /**
     * @param list<string> $argv
     */
    public function __construct(private readonly array $argv) {}

    public function run(): int
    {
        foreach (array_slice($this->argv, 1) as $arg) {
            match (true) {
                $arg === '--dry-run' => $this->dryRun = true,
                $arg === '--verbose' || $arg === '-v' => $this->verbose = true,
                $arg === '--write' => $this->allowWrites = true,
                $arg === '--help' || $arg === '-h' => $this->usage(),
                str_starts_with($arg, '--config=') => Configuration::overload(substr($arg, 9)),
                default => $this->fail("Unknown option: {$arg}") ,
            };
        }

        if ($this->failed > 0) {
            return 1;
        }

        $this->heading('Granola API — live checks');

        if (!$this->connect()) {
            return 1;
        }

        $this->checkFolders();
        $noteId = $this->checkNotes();
        $this->checkNote($noteId);
        $this->checkTranscript($noteId);
        $this->checkAudit();
        $this->checkWebhookEndpoints();
        $this->checkSignatureRoundTrip();

        if ($this->allowWrites) {
            $this->checkWebhookLifecycle();
        } else {
            $this->skip('Webhook endpoint lifecycle', 'pass --write to create and delete a real endpoint');
        }

        return $this->summary();
    }

    // ── Checks ──────────────────────────────────────────────────────────

    private function connect(): bool
    {
        if ($this->dryRun) {
            $this->skip('Connect', 'dry run — no requests will be made');
            $this->summary();
            return false;
        }

        $key = Configuration::get('testing.api_key') ?: getenv('GRANOLA_API_KEY');

        if (!is_string($key) || trim($key) === '') {
            $this->fail(
                'Connect',
                'No API key. Set testing.api_key in granolaapi.config.json, or export GRANOLA_API_KEY.'
            );
            $this->summary();
            return false;
        }

        $this->granola = Granola::connect(trim($key));
        $this->pass('Connect', $this->granola->getFingerprint());

        return true;
    }

    private function checkFolders(): void
    {
        $folders = Folder::list($this->granola)->pageSize(5)->fetch();

        if ($folders->succeeded() !== true) {
            $this->fail('List folders', $this->reason($folders->lastResponse()?->errorMessage()));
            return;
        }

        $this->pass('List folders', count($folders) . ' returned, hasMore=' . var_export($folders->hasMore(), true));

        foreach ($folders->all() as $folder) {
            $this->detail(sprintf('  %s  %s', $folder->id(), (string) $folder->name()));
        }
    }

    private function checkNotes(): ?string
    {
        $notes = Note::list($this->granola)->pageSize(5)->fetch();

        if ($notes->succeeded() !== true) {
            $this->fail('List notes', $this->reason($notes->lastResponse()?->errorMessage()));
            return null;
        }

        $this->pass('List notes', count($notes) . ' returned, cursor=' . ($notes->cursor() ?? 'null'));

        foreach ($notes->all() as $note) {
            $this->detail(sprintf('  %s  %s', $note->id(), (string) $note->title));
        }

        if ($notes->isEmpty()) {
            $this->skip('Note detail checks', 'this key can see no notes with a generated summary');
            return null;
        }

        return $notes->first()?->id();
    }

    private function checkNote(?string $noteId): void
    {
        if ($noteId === null) {
            return;
        }

        $note = Note::find($noteId, true, $this->granola);

        if ($note->succeeded() !== true) {
            $this->fail('Get note', $this->reason($note->lastResponse()?->errorMessage()));
            return;
        }

        $missing = [];
        foreach (['id', 'object', 'title', 'owner', 'created_at', 'updated_at', 'web_url', 'summary_text'] as $field) {
            if (!isset($note->$field) && $note->get($field) === null && $field !== 'title') {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            $this->fail('Get note', 'documented fields absent from the response: ' . implode(', ', $missing));
            return;
        }

        $this->pass('Get note', $note->transcriptWasTooLarge()
            ? 'transcript too large to inline (expected for long meetings)'
            : ($note->hasInlineTranscript() ? 'transcript inlined' : 'no transcript inlined'));

        if ($note->unmapped() !== []) {
            $this->notice('Get note', 'Granola sent fields this SDK does not model: '
                . implode(', ', array_keys($note->unmapped())));
        }
    }

    private function checkTranscript(?string $noteId): void
    {
        if ($noteId === null) {
            return;
        }

        $note = Note::find($noteId, false, $this->granola);
        $transcript = $note->transcript()->pageSize(10)->fetch();

        if ($transcript->succeeded() !== true) {
            $this->fail('Get transcript', $this->reason($transcript->lastResponse()?->errorMessage()));
            return;
        }

        $this->pass('Get transcript', count($transcript) . ' items, hasMore='
            . var_export($transcript->hasMore(), true));

        $first = $transcript->first();
        if ($first !== null) {
            $this->detail('  ' . mb_substr($first->toLine(), 0, 100));
        }
    }

    private function checkAudit(): void
    {
        $events = AuditEvent::list($this->granola)->pageSize(5)->fetch();
        $status = $events->lastResponse()?->responseCode ?? 0;

        if (in_array($status, [401, 403], true)) {
            $this->skip('List audit events', 'this key has no audit access (workspace key on Enterprise required)');
            return;
        }

        if ($events->succeeded() !== true) {
            $this->fail('List audit events', $this->reason($events->lastResponse()?->errorMessage()));
            return;
        }

        $this->pass('List audit events', count($events) . ' returned');

        foreach ($events->all() as $event) {
            $this->detail(sprintf('  %-32s %s', $event->action(), $event->actorLabel()));
        }
    }

    private function checkWebhookEndpoints(): void
    {
        $endpoints = WebhookEndpoint::all($this->granola);

        if ($endpoints->succeeded() !== true) {
            $this->fail('List webhook endpoints', $this->reason($endpoints->lastResponse()?->errorMessage()));
            return;
        }

        $this->pass('List webhook endpoints', count($endpoints) . ' registered');

        foreach ($endpoints->all() as $endpoint) {
            $this->detail(sprintf(
                '  %s  %s  %s%s',
                $endpoint->id(),
                $endpoint->isEnabled() ? 'enabled ' : 'paused  ',
                (string) $endpoint->get('url'),
                $endpoint->isUrlRedacted() ? ' (redacted)' : ''
            ));
        }
    }

    /**
     * Signing and verification are pure local computation, so this runs without
     * touching Granola — it guards the one piece of the SDK where a silent
     * regression would accept forged deliveries.
     */
    private function checkSignatureRoundTrip(): void
    {
        $secret = 'whsec_' . base64_encode(random_bytes(24));
        $verifier = new WebhookVerifier($secret);
        $body = '{"event_id":"evt_live","event_type":"note.generated","note_id":"not_liveCheck0001"}';
        $timestamp = time();

        $headers = [
            WebhookHeaders::ID => 'evt_live',
            WebhookHeaders::TIMESTAMP => (string) $timestamp,
            WebhookHeaders::SIGNATURE => $verifier->signatureHeader('evt_live', $timestamp, $body),
        ];

        $accepted = $verifier->isValid($body, $headers);
        $rejected = !$verifier->isValid($body . ' ', $headers);
        $parsed = Webhook::parse($body, $headers, $secret)->noteId === 'not_liveCheck0001';

        if ($accepted && $rejected && $parsed) {
            $this->pass('Webhook signature round trip', 'valid accepted, tampered rejected');
            return;
        }

        $this->fail('Webhook signature round trip', sprintf(
            'accepted=%s rejected-tampered=%s parsed=%s',
            var_export($accepted, true),
            var_export($rejected, true),
            var_export($parsed, true)
        ));
    }

    private function checkWebhookLifecycle(): void
    {
        $url = Configuration::get('testing.webhook_url');
        if (!is_string($url) || $url === '') {
            $this->skip('Webhook endpoint lifecycle', 'set testing.webhook_url to an HTTPS URL you control');
            return;
        }

        $endpoint = WebhookEndpoint::register($url, ['personal'], connection: $this->granola);

        if ($endpoint->succeeded() !== true) {
            $this->fail('Create webhook endpoint', $this->reason($endpoint->lastResponse()?->errorMessage()));
            return;
        }

        $secret = $endpoint->signingSecret();
        $this->pass('Create webhook endpoint', $endpoint->id() . ', signing secret '
            . ($secret === null ? 'MISSING' : 'returned (' . strlen($secret) . ' chars)'));

        if ($secret === null) {
            $this->fail('Create webhook endpoint', 'signing_secret absent — it is only ever returned here');
        }

        $endpoint->disable();
        $this->assert('Pause webhook endpoint', $endpoint->isEnabled() === false, 'endpoint still reports enabled');

        $deleted = $endpoint->delete();
        $this->assert('Delete webhook endpoint', $deleted, 'Granola did not confirm the deletion');
    }

    // ── Output ──────────────────────────────────────────────────────────

    private function assert(string $name, bool $condition, string $failureReason): void
    {
        $condition ? $this->pass($name) : $this->fail($name, $failureReason);
    }

    private function pass(string $name, string $detail = ''): void
    {
        $this->passed++;
        echo self::GREEN . '  PASS  ' . self::RESET . $name
            . ($detail !== '' ? self::DIM . ' — ' . $detail . self::RESET : '') . PHP_EOL;
    }

    private function fail(string $name, string $detail = ''): void
    {
        $this->failed++;
        echo self::RED . '  FAIL  ' . self::RESET . $name
            . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    }

    private function skip(string $name, string $reason): void
    {
        $this->skipped++;
        echo self::YELLOW . '  SKIP  ' . self::RESET . $name . self::DIM . ' — ' . $reason . self::RESET . PHP_EOL;
    }

    private function notice(string $name, string $message): void
    {
        echo self::YELLOW . '  NOTE  ' . self::RESET . $name . ' — ' . $message . PHP_EOL;
    }

    private function detail(string $line): void
    {
        if ($this->verbose) {
            echo self::DIM . $line . self::RESET . PHP_EOL;
        }
    }

    private function heading(string $title): void
    {
        echo PHP_EOL . $title . PHP_EOL . str_repeat('─', mb_strlen($title)) . PHP_EOL;
    }

    private function reason(?string $message): string
    {
        return $message ?? 'no error message returned';
    }

    private function summary(): int
    {
        echo PHP_EOL . sprintf(
            "%d passed, %d failed, %d skipped\n\n",
            $this->passed,
            $this->failed,
            $this->skipped
        );

        return $this->failed > 0 ? 1 : 0;
    }

    private function usage(): void
    {
        echo <<<TXT

        Granola API — live checks

          tests/validate [options]

        Options
          --write             Also create, pause and delete one webhook endpoint
          --config=PATH       Load a granolaapi.config.json override first
          --dry-run           Parse options and stop before any request
          --verbose, -v       Print the records each check returned
          --help, -h          Show this

        Credentials
          testing.api_key in a granolaapi.config.json override, or GRANOLA_API_KEY
          in the environment. --write also needs testing.webhook_url.


        TXT;

        exit(0);
    }
}
