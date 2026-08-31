<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Enum;

/**
 * Which notes a webhook endpoint (or API key) can see.
 */
enum WebhookScope: string
{
    /** Notes you own, notes shared directly with you, and private folders shared with you. */
    case Personal = 'personal';

    /** Notes visible to everyone in the workspace. */
    case Public = 'public';

    /**
     * Reported for endpoints created with a workspace API key: public workspace
     * notes plus notes in spaces with Granola API access enabled. A workspace
     * key must pass exactly ["workspace"].
     */
    case Workspace = 'workspace';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
