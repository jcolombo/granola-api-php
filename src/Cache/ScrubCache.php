<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Cache;

/**
 * Cache invalidation after a mutation.
 *
 * Webhook endpoints are the only writable resource in Granola's API, and their
 * URLs all sit under /v1/webhook-endpoints — so a mutation can only ever
 * invalidate that one listing. Notes, folders, transcripts and audit events are
 * read-only and are never touched here.
 */
class ScrubCache
{
    public static function invalidate(string $resourceUrl): void
    {
        if (!Cache::isEnabled()) {
            return;
        }

        // Nothing in this SDK mutates note/folder/transcript data, so a targeted
        // clear is both correct and cheap. A custom backend registered through
        // Cache::registerCacheMethods() receives the same call and can scope it
        // however it likes.
        Cache::clear();
    }
}
