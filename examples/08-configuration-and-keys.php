<?php

declare(strict_types=1);

/**
 * 08 — Configuration, several API keys, audit events, and error handling.
 *
 * A personal key and a workspace key see genuinely different sets of notes, so
 * the SDK holds them side by side rather than swapping one for the other.
 *
 *     export GRANOLA_API_KEY=grn_personal_key
 *     export GRANOLA_WORKSPACE_KEY=grn_workspace_key   # optional
 */

use Jcolombo\GranolaApiPhp\Configuration;
use Jcolombo\GranolaApiPhp\Entity\Resource\AuditEvent;
use Jcolombo\GranolaApiPhp\Entity\Resource\Note;
use Jcolombo\GranolaApiPhp\Exception\ApiException;
use Jcolombo\GranolaApiPhp\Granola;
use Jcolombo\GranolaApiPhp\Utility\Log;
use Jcolombo\GranolaApiPhp\Utility\RateLimiter;

require __DIR__ . '/bootstrap.php';

// ── Configuration ───────────────────────────────────────────────────────

echo "Configuration\n";
echo '  url:            ', Configuration::get('connection.url'), "\n";
echo '  timeout:        ', Configuration::get('connection.timeout'), "s\n";
echo '  burst limit:    ', Configuration::get('rateLimit.burstLimit'), ' per ',
    Configuration::get('rateLimit.burstWindowSeconds'), "s\n";
echo '  sustained:      ', Configuration::get('rateLimit.perMinute'), "/min\n";
echo '  files merged:   ', count(Configuration::loadedPaths()), "\n\n";

// Route the SDK's log lines into your own logger instead of a file.
Log::registerWriter(static function (string $message, array $context): void {
    echo '  [log] ', $message, $context === [] ? '' : ' ' . json_encode($context), "\n";
});
Configuration::set('log.requests', true);

// ── A second key, alongside the first ───────────────────────────────────

$workspaceKey = getenv('GRANOLA_WORKSPACE_KEY');

if (is_string($workspaceKey) && $workspaceKey !== '') {
    Granola::connect($workspaceKey, 'workspace');

    echo "Two keys, side by side\n";
    echo '  personal notes:  ', count(Note::list()->pageSize(5)->fetch()), "\n";
    echo '  workspace notes: ', count(Note::list('workspace')->pageSize(5)->fetch()), "\n";
    echo '  connections:     ', implode(', ', array_keys(Granola::connections())), "\n\n";

    // Audit events need a workspace key on an Enterprise plan.
    Configuration::set('error.throwOnApiError', false);
    $events = AuditEvent::list('workspace')->pageSize(5)->fetch();

    if ($events->succeeded() === true) {
        echo "Recent audit events\n";
        foreach ($events as $event) {
            printf("  %-28s %-24s %s\n", $event->action(), $event->actorLabel(), $event->occurredAt()?->format('Y-m-d H:i'));
        }
    } else {
        echo 'Audit events unavailable (HTTP ', $events->lastResponse()?->responseCode, ") — needs an Enterprise workspace key\n";
    }
    Configuration::set('error.throwOnApiError', true);
    echo "\n";
} else {
    echo "Set GRANOLA_WORKSPACE_KEY to see two keys used side by side.\n\n";
}

// ── Error handling, both styles ─────────────────────────────────────────

echo "Errors as exceptions (error.throwOnApiError = true)\n";
try {
    Note::find('not_doesNotExist00');
    echo "  no error — that ID unexpectedly exists\n";
} catch (ApiException $e) {
    echo '  ', $e->getMessage(), "\n";
    echo '  notFound: ', var_export($e->isNotFound(), true),
        '  unauthorized: ', var_export($e->isUnauthorized(), true), "\n";
}

echo "\nErrors as inspectable responses (the default)\n";
Configuration::set('error.throwOnApiError', false);

$note = Note::find('not_doesNotExist00');
echo '  succeeded: ', var_export($note->succeeded(), true), "\n";
echo '  status:    ', $note->lastResponse()?->responseCode, "\n";
echo '  message:   ', $note->lastResponse()?->errorMessage(), "\n";

// ── Rate limit usage ────────────────────────────────────────────────────

// The limiter tracks usage per API key, and per process — it cannot see what
// other workers sharing this key have spent.
echo "\nRequests this process made in the last minute: ",
    RateLimiter::usage((string) Configuration::get('connection.apiKey')), "\n";
