<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Webhook;

use Jcolombo\GranolaApiPhp\Entity\Collection\TranscriptCollection;
use Jcolombo\GranolaApiPhp\Entity\Resource\Note;
use Jcolombo\GranolaApiPhp\Enum\WebhookEventType;
use Jcolombo\GranolaApiPhp\Exception\WebhookPayloadException;
use Jcolombo\GranolaApiPhp\Granola;

/**
 * One parsed webhook delivery.
 *
 * Granola's payload is deliberately small — it names the note, it does not
 * carry it — so the content is fetched lazily, and only if something actually
 * asks for it:
 *
 *     $event->noteId;         // always present, costs nothing
 *     $event->note();         // one GET, memoised
 *     $event->note(true);     // with the transcript inline where possible
 *
 * An event type this SDK version does not know about is not an error: `type` is
 * null and `rawType` holds whatever Granola sent, so a receiver keeps running
 * when Granola ships a new event.
 */
final class WebhookEvent implements \JsonSerializable
{
    private ?Note $note = null;

    private bool $noteHasTranscript = false;

    /**
     * @param list<string>         $changedFields
     * @param array<string, mixed> $payload
     */
    private function __construct(
        public readonly string $eventId,
        public readonly ?WebhookEventType $type,
        public readonly string $rawType,
        public readonly string $noteId,
        public readonly ?\DateTimeImmutable $occurredAt,
        public readonly array $changedFields,
        public readonly array $payload,
        private ?Granola $connection = null,
    ) {}

    /**
     * Build an event from a decoded payload.
     *
     * @param array<string, mixed> $payload
     *
     * @throws WebhookPayloadException when a required field is missing
     */
    public static function fromPayload(array $payload, ?Granola $connection = null): self
    {
        foreach (['event_id', 'event_type', 'note_id'] as $required) {
            if (!isset($payload[$required]) || !is_scalar($payload[$required])) {
                throw WebhookPayloadException::missingField($required);
            }
        }

        $rawType = (string) $payload['event_type'];

        $changed = $payload['data']['changed_fields'] ?? [];
        $changedFields = is_array($changed) ? array_values(array_map('strval', $changed)) : [];

        return new self(
            eventId: (string) $payload['event_id'],
            type: WebhookEventType::tryFrom($rawType),
            rawType: $rawType,
            noteId: (string) $payload['note_id'],
            occurredAt: self::toDate($payload['occurred_at'] ?? null),
            changedFields: $changedFields,
            payload: $payload,
            connection: $connection,
        );
    }

    // ── Lazy content ────────────────────────────────────────────────────

    /**
     * Fetch the note this event is about. Memoised, so calling it repeatedly in
     * one handler costs a single request.
     *
     * @param bool $withTranscript Ask for the transcript inline. Granola falls
     *                             back to the paged endpoint automatically when
     *                             the transcript is too large.
     *
     * @throws WebhookPayloadException when no connection is available
     */
    public function note(bool $withTranscript = false): Note
    {
        if ($this->connection === null) {
            throw WebhookPayloadException::noConnection();
        }

        // A cached note fetched without the transcript cannot answer a request
        // that wants one, so refetch in that case only.
        if ($this->note === null || ($withTranscript && !$this->noteHasTranscript)) {
            $this->note = Note::find($this->noteId, $withTranscript, $this->connection);
            $this->noteHasTranscript = $withTranscript;
        }

        return $this->note;
    }

    /**
     * The note's transcript, paging from the dedicated endpoint when it did not
     * arrive inline.
     */
    public function transcript(): TranscriptCollection
    {
        return $this->note(true)->transcript();
    }

    /**
     * Attach (or replace) the connection used to fetch content.
     *
     * Returns a new instance; the event itself is immutable.
     */
    public function withConnection(Granola $connection): self
    {
        return new self(
            eventId: $this->eventId,
            type: $this->type,
            rawType: $this->rawType,
            noteId: $this->noteId,
            occurredAt: $this->occurredAt,
            changedFields: $this->changedFields,
            payload: $this->payload,
            connection: $connection,
        );
    }

    public function hasConnection(): bool
    {
        return $this->connection !== null;
    }

    // ── Type checks ─────────────────────────────────────────────────────

    public function is(WebhookEventType|string $type): bool
    {
        return $this->rawType === ($type instanceof WebhookEventType ? $type->value : $type);
    }

    public function isGenerated(): bool
    {
        return $this->type === WebhookEventType::NoteGenerated;
    }

    public function isEdited(): bool
    {
        return $this->type === WebhookEventType::NoteEdited;
    }

    public function isAccessGranted(): bool
    {
        return $this->type === WebhookEventType::NoteAccessGranted;
    }

    /**
     * True when Granola sent an event type this SDK version does not model.
     * Handle it from `rawType` and `payload`, or ignore it.
     */
    public function isUnknownType(): bool
    {
        return $this->type === null;
    }

    /**
     * True when the named field is listed in `data.changed_fields`.
     *
     * Only note.edited carries these, and today the list is always ['summary'].
     */
    public function changed(string $field): bool
    {
        return in_array($field, $this->changedFields, true);
    }

    // ── Serialisation ───────────────────────────────────────────────────

    /**
     * The original payload, unmodified — the right thing to persist for
     * deduplication or replay.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->payload;
    }

    private static function toDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
