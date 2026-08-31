<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Enum;

/**
 * The note events Granola delivers to a webhook endpoint.
 *
 * Granola adds events over time, so a payload carrying an unrecognised type is
 * never an error here: WebhookEvent keeps `type` null and exposes the original
 * string as `rawType`.
 */
enum WebhookEventType: string
{
    /** The first AI summary for a note has been generated. */
    case NoteGenerated = 'note.generated';

    /** A note's summary was edited or regenerated. */
    case NoteEdited = 'note.edited';

    /** A note was shared with you, directly or through a folder. */
    case NoteAccessGranted = 'note.access_granted';

    /**
     * Every event name, for subscribing an endpoint to all of them.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::NoteGenerated => 'Note generated',
            self::NoteEdited => 'Note edited',
            self::NoteAccessGranted => 'Note access granted',
        };
    }
}
