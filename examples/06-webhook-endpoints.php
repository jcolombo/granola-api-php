<?php

declare(strict_types=1);

/**
 * 06 — Managing webhook endpoints.
 *
 * The only writable resource in Granola's API.
 *
 * Read-only by default. Pass an HTTPS URL you control to register, pause and
 * delete a real endpoint:
 *
 *     php examples/06-webhook-endpoints.php https://your-tunnel.example.com/granola-webhooks
 */

use Jcolombo\GranolaApiPhp\Entity\Resource\WebhookEndpoint;
use Jcolombo\GranolaApiPhp\Enum\WebhookEventType;
use Jcolombo\GranolaApiPhp\Enum\WebhookScope;

require __DIR__ . '/bootstrap.php';

// ── What is registered ──────────────────────────────────────────────────

$endpoints = WebhookEndpoint::all();

echo 'Registered endpoints: ', count($endpoints), "\n";

foreach ($endpoints as $endpoint) {
    printf(
        "  %s  %-8s  %s%s\n",
        $endpoint->id(),
        $endpoint->isEnabled() ? 'enabled' : 'paused',
        (string) $endpoint->get('url'),
        $endpoint->isUrlRedacted() ? '  (redacted — not created by this key)' : ''
    );
    echo '      events: ', implode(', ', array_map(
        static fn (WebhookEventType $e): string => $e->value,
        $endpoint->events()
    )), "\n";
    echo '      scopes: ', implode(', ', array_map(
        static fn (WebhookScope $s): string => $s->value,
        $endpoint->scopes()
    )), "\n";
    echo '      folders: ', implode(', ', $endpoint->folderIds()) ?: 'all', "\n";
}

echo "\nEnabled: ", count($endpoints->enabled()), '   paused: ', count($endpoints->paused()), "\n";

// ── The full lifecycle ──────────────────────────────────────────────────

$url = $argv[1] ?? null;

if ($url === null) {
    echo "\nPass an HTTPS URL you control to run the create → pause → delete cycle.\n";
    exit(0);
}

echo "\nRegistering ", $url, "\n";

$endpoint = WebhookEndpoint::register(
    url: $url,
    scopes: [WebhookScope::Personal, WebhookScope::Public],
    events: [WebhookEventType::NoteGenerated, WebhookEventType::NoteEdited],
);

echo '  id:     ', $endpoint->id(), "\n";

// ⚠️ This is the only time Granola will ever show the signing secret.
$secret = $endpoint->signingSecret();
echo '  secret: ', $secret === null ? 'MISSING' : substr((string) $secret, 0, 12) . '… (store it now — shown once)', "\n";

echo "\nPausing\n";
$endpoint->disable();
echo '  enabled: ', var_export($endpoint->isEnabled(), true), "\n";

echo "\nNarrowing the event subscription\n";
$endpoint->subscribeTo([WebhookEventType::NoteGenerated])->save();
echo '  events: ', implode(', ', array_map(
    static fn (WebhookEventType $e): string => $e->value,
    $endpoint->events()
)), "\n";

echo "\nDeleting\n";
echo '  deleted: ', var_export($endpoint->delete(), true), "\n";
