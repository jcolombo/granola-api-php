<?php

declare(strict_types=1);

/**
 * 07 — A webhook receiver.
 *
 * A complete, production-shaped endpoint in plain PHP. Serve it behind HTTPS
 * and register that URL with Granola (see 06).
 *
 *     export GRANOLA_API_KEY=grn_...
 *     export GRANOLA_WEBHOOK_SECRET=whsec_...
 *     php -S 0.0.0.0:8080 examples/07-webhook-receiver.php
 *
 * Run it with no web server to see a self-test instead: a delivery is signed
 * locally, verified, and parsed.
 */

use Jcolombo\GranolaApiPhp\Configuration;
use Jcolombo\GranolaApiPhp\Exception\SignatureVerificationException;
use Jcolombo\GranolaApiPhp\Exception\WebhookPayloadException;
use Jcolombo\GranolaApiPhp\Granola;
use Jcolombo\GranolaApiPhp\Webhook\Webhook;
use Jcolombo\GranolaApiPhp\Webhook\WebhookEvent;
use Jcolombo\GranolaApiPhp\Webhook\WebhookHeaders;
use Jcolombo\GranolaApiPhp\Webhook\WebhookVerifier;

require dirname(__DIR__) . '/vendor/autoload.php';

$signingSecret = getenv('GRANOLA_WEBHOOK_SECRET') ?: null;

if (PHP_SAPI === 'cli') {
    selfTest();
    exit(0);
}

// ── The endpoint ────────────────────────────────────────────────────────

Configuration::set('connection.apiKey', (string) getenv('GRANOLA_API_KEY'));

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

// The signature covers the exact bytes Granola sent. Never re-encode a decoded
// payload — key order and whitespace will differ and verification will fail.
$rawBody = file_get_contents('php://input') ?: '';

try {
    $event = Webhook::parse(
        $rawBody,
        WebhookHeaders::fromGlobals(),
        $signingSecret,
        Granola::connect(),
    );
} catch (SignatureVerificationException $e) {
    // Hostile or replayed. Do not parse the body; do not act on it.
    error_log('Rejected Granola webhook: ' . $e->getMessage());
    http_response_code(400);
    exit;
} catch (WebhookPayloadException $e) {
    error_log('Unparseable Granola webhook: ' . $e->getMessage());
    http_response_code(400);
    exit;
}

// Every retry of a delivery carries the same event_id, and a network failure
// after your handler succeeded is indistinguishable from a real failure — so
// deduplicate, with a store that outlives the four-day retry window.
if (alreadyHandled($event->eventId)) {
    http_response_code(200);
    exit;
}
recordDelivery($event->eventId);

// Granola allows 15 seconds. Queue the work; do not fetch and transform here.
match (true) {
    $event->isGenerated() => enqueue('import-note', $event->noteId),
    $event->isEdited() => enqueue('refresh-summary', $event->noteId),
    $event->isAccessGranted() => enqueue('import-note', $event->noteId),
    // Granola adds events over time — an unknown one is delivered fine, we just
    // do not act on it. Anything but 200 would earn four days of retries.
    default => error_log('Unhandled Granola event: ' . $event->rawType),
};

http_response_code(200);
echo 'ok';

// ── Stand-ins for your own infrastructure ───────────────────────────────

function alreadyHandled(string $eventId): bool
{
    return is_file(sys_get_temp_dir() . '/granola-delivery-' . $eventId);
}

function recordDelivery(string $eventId): void
{
    touch(sys_get_temp_dir() . '/granola-delivery-' . $eventId);
}

function enqueue(string $job, string $noteId): void
{
    error_log("queued {$job} for {$noteId}");
}

// ── Self-test ───────────────────────────────────────────────────────────

/**
 * Signs a delivery locally and runs it through the same parse path, so the
 * receiver can be exercised without Granola, a tunnel, or a real note.
 */
function selfTest(): void
{
    $secret = 'whsec_' . base64_encode('example-signing-secret');
    $verifier = new WebhookVerifier($secret);

    $body = (string) json_encode([
        'event_id' => 'evt_example_01',
        'event_type' => 'note.edited',
        'note_id' => 'not_1d3tmYTlCICgjy',
        'occurred_at' => '2026-08-31T09:15:00Z',
        'data' => ['changed_fields' => ['summary']],
    ]);

    $timestamp = time();
    $headers = [
        WebhookHeaders::ID => 'evt_example_01',
        WebhookHeaders::TIMESTAMP => (string) $timestamp,
        WebhookHeaders::SIGNATURE => $verifier->signatureHeader('evt_example_01', $timestamp, $body),
    ];

    echo "Signed delivery\n";
    echo '  ', WebhookHeaders::SIGNATURE, ': ', $headers[WebhookHeaders::SIGNATURE], "\n\n";

    $event = Webhook::parse($body, $headers, $secret);
    describe($event);

    echo "\nTampered body\n";
    try {
        Webhook::parse(str_replace('not_1d3tmYTlCICgjy', 'not_attackerIdxx', $body), $headers, $secret);
        echo "  ACCEPTED — this is a bug\n";
    } catch (SignatureVerificationException $e) {
        echo '  rejected: ', $e->getMessage(), "\n";
    }

    echo "\nAn event type this SDK version predates\n";
    $future = (string) json_encode([
        'event_id' => 'evt_example_02',
        'event_type' => 'note.archived',
        'note_id' => 'not_1d3tmYTlCICgjy',
        'occurred_at' => '2026-08-31T09:20:00Z',
    ]);
    $futureHeaders = [
        WebhookHeaders::ID => 'evt_example_02',
        WebhookHeaders::TIMESTAMP => (string) $timestamp,
        WebhookHeaders::SIGNATURE => $verifier->signatureHeader('evt_example_02', $timestamp, $future),
    ];
    describe(Webhook::parse($future, $futureHeaders, $secret));
}

function describe(WebhookEvent $event): void
{
    echo '  eventId:       ', $event->eventId, "\n";
    echo '  type:          ', $event->type?->value ?? 'unknown', "\n";
    echo '  rawType:       ', $event->rawType, "\n";
    echo '  noteId:        ', $event->noteId, "\n";
    echo '  occurredAt:    ', $event->occurredAt?->format(DATE_ATOM) ?? 'null', "\n";
    echo '  changedFields: ', implode(', ', $event->changedFields) ?: '(none)', "\n";
    echo '  unknown type:  ', var_export($event->isUnknownType(), true), "\n";
}
