<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Entity\Resource;

use Jcolombo\GranolaApiPhp\Configuration;
use Jcolombo\GranolaApiPhp\Entity\AbstractResource;
use Jcolombo\GranolaApiPhp\Entity\Collection\NoteCollection;
use Jcolombo\GranolaApiPhp\Entity\Collection\TranscriptCollection;
use Jcolombo\GranolaApiPhp\Entity\Value\CalendarEvent;
use Jcolombo\GranolaApiPhp\Entity\Value\User;
use Jcolombo\GranolaApiPhp\Exception\TranscriptTooLargeException;
use Jcolombo\GranolaApiPhp\Granola;
use Jcolombo\GranolaApiPhp\Request;

/**
 * A Granola meeting note: its AI summary, attendees, calendar event and transcript.
 *
 *     $note = Note::find('not_1d3tmYTlCICgjy');
 *     echo $note->title;
 *     echo $note->summary_markdown;
 *
 * Read-only — Granola's API has no endpoint that writes a note.
 *
 * Note that the API only returns notes that already have a generated AI summary
 * and transcript, so a note visible in the desktop app may not be listable yet.
 */
class Note extends AbstractResource
{
    public const LABEL = 'Note';
    public const API_PATH = 'v1/notes';
    public const OBJECT_TYPE = 'note';
    public const ID_PREFIX = 'not_';

    public const PROP_TYPES = [
        'id' => 'id',
        'object' => 'text',
        'title' => 'text',
        'owner' => 'value:' . User::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'web_url' => 'uri',
        'calendar_event' => 'value:' . CalendarEvent::class,
        'attendees' => 'valueList:' . User::class,
        'folder_membership' => 'valueList:' . Folder::class,
        'summary_text' => 'text',
        'summary_markdown' => 'text',
        'transcript' => 'valueList:' . TranscriptItem::class,
    ];

    /** Granola answered 413 for the inline transcript; page it instead. */
    protected bool $transcriptTooLarge = false;

    // ── Reading ─────────────────────────────────────────────────────────

    /**
     * Start a filtered, cursor-paginated listing of notes.
     */
    public static function list(null|string|Granola $connection = null): NoteCollection
    {
        /** @var NoteCollection */
        return parent::list($connection);
    }

    /**
     * Fetch one note by ID.
     *
     * @param bool $withTranscript Ask for the transcript inline. Granola may
     *                             answer 413 for long meetings; see fetch().
     */
    public static function find(
        string $id,
        bool $withTranscript = false,
        null|string|Granola $connection = null
    ): static {
        return static::new($connection)->fetch($id, $withTranscript);
    }

    /**
     * Load this instance from the API.
     *
     * When $withTranscript is set and Granola replies 413 TRANSCRIPT_TOO_LARGE,
     * the note is re-fetched without the transcript and flagged — transcript()
     * then pages it from the dedicated endpoint. Turn
     * `notes.autoFallbackLargeTranscript` off to get a
     * TranscriptTooLargeException instead.
     *
     * @throws TranscriptTooLargeException
     */
    public function fetch(string $id, bool $withTranscript = false): static
    {
        $query = $withTranscript ? ['include' => 'transcript'] : [];

        $response = Request::get($this->connection(), Request::path(static::API_PATH, $id), $query);
        $this->lastResponse = $response;

        if ($response->responseCode === 413) {
            $this->transcriptTooLarge = true;

            if (!Configuration::get('notes.autoFallbackLargeTranscript', true)) {
                throw new TranscriptTooLargeException($id);
            }

            $response = Request::get($this->connection(), Request::path(static::API_PATH, $id));
            $this->lastResponse = $response;
        }

        if ($response->success && $response->body !== null) {
            $this->hydrate($response->body);
        }

        return $this;
    }

    /**
     * Re-read this note from the API.
     */
    public function refresh(bool $withTranscript = false): static
    {
        $id = $this->id();
        if ($id === null) {
            return $this;
        }
        return $this->fetch($id, $withTranscript);
    }

    // ── Transcript ──────────────────────────────────────────────────────

    /**
     * This note's transcript.
     *
     * If the transcript already arrived inline, the collection is returned
     * pre-filled and no request is made. Otherwise it is bound to
     * GET /v1/notes/{id}/transcript and pages on fetch():
     *
     *     foreach ($note->transcript()->each() as $item) { ... }
     */
    public function transcript(): TranscriptCollection
    {
        $id = (string) $this->id();
        $collection = new TranscriptCollection(TranscriptItem::class, $this->connection());
        $collection->forNote($id);

        $inline = $this->props['transcript'] ?? null;
        if (is_array($inline) && $inline !== []) {
            $collection->seed($inline);
        }

        return $collection;
    }

    /**
     * True when the transcript arrived with this note and needs no extra call.
     */
    public function hasInlineTranscript(): bool
    {
        $inline = $this->props['transcript'] ?? null;
        return is_array($inline) && $inline !== [];
    }

    /**
     * True when Granola refused to inline the transcript because of its size.
     */
    public function transcriptWasTooLarge(): bool
    {
        return $this->transcriptTooLarge;
    }

    /**
     * The inline transcript flattened to text, one line per item.
     *
     * Only covers what is already loaded — call transcript()->fetchAll() first
     * for a note whose transcript was not inlined.
     */
    public function transcriptText(bool $withSpeakers = true, string $separator = "\n"): string
    {
        $lines = [];
        foreach ((array) ($this->props['transcript'] ?? []) as $item) {
            if (!$item instanceof TranscriptItem) {
                continue;
            }
            $lines[] = $withSpeakers ? $item->toLine() : (string) $item->get('text');
        }
        return implode($separator, $lines);
    }

    // ── Convenience ─────────────────────────────────────────────────────

    /**
     * The best summary Granola produced: markdown when present, else plain text.
     */
    public function summary(): ?string
    {
        $markdown = $this->get('summary_markdown');
        if (is_string($markdown) && $markdown !== '') {
            return $markdown;
        }
        $text = $this->get('summary_text');
        return is_string($text) && $text !== '' ? $text : null;
    }

    /**
     * @return list<string>
     */
    public function attendeeEmails(): array
    {
        return array_values(array_map(
            static fn (User $u): string => $u->email,
            array_filter((array) $this->get('attendees', []), static fn (mixed $u): bool => $u instanceof User)
        ));
    }

    /**
     * Folders this note belongs to.
     *
     * @return list<Folder>
     */
    public function folders(): array
    {
        return array_values(array_filter(
            (array) $this->get('folder_membership', []),
            static fn (mixed $f): bool => $f instanceof Folder
        ));
    }

    /**
     * @return list<string>
     */
    public function folderIds(): array
    {
        return array_values(array_filter(array_map(
            static fn (Folder $f): ?string => $f->id(),
            $this->folders()
        )));
    }

    public function isInFolder(string $folderId): bool
    {
        return in_array($folderId, $this->folderIds(), true);
    }

    public function owner(): ?User
    {
        $owner = $this->get('owner');
        return $owner instanceof User ? $owner : null;
    }

    public function calendarEvent(): ?CalendarEvent
    {
        $event = $this->get('calendar_event');
        return $event instanceof CalendarEvent ? $event : null;
    }

    /**
     * True when the note came from a scheduled calendar meeting rather than an
     * ad-hoc recording.
     */
    public function hasCalendarEvent(): bool
    {
        return $this->calendarEvent() !== null;
    }
}
