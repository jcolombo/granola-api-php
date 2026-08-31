<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Entity\Collection;

use Jcolombo\GranolaApiPhp\Entity\AbstractCollection;
use Jcolombo\GranolaApiPhp\Entity\Resource\Note;
use Jcolombo\GranolaApiPhp\Entity\Resource\TranscriptItem;
use Jcolombo\GranolaApiPhp\Request;

/**
 * A note's transcript, from GET /v1/notes/{note_id}/transcript.
 *
 * Reached through Note::transcript() rather than built directly. When the note
 * already carried its transcript inline, the collection arrives pre-seeded and
 * makes no request at all; otherwise it pages, up to 100 items at a time.
 *
 *     foreach ($note->transcript()->each() as $item) {
 *         echo $item->toLine(), "\n";
 *     }
 *
 * Transcript items have no IDs, so this collection is ordered and indexed
 * positionally — find() and string offsets do not apply.
 */
class TranscriptCollection extends AbstractCollection
{
    public const RESULT_KEY = 'transcript';

    protected const PAGE_SIZE_CONFIG = 'transcriptPageSize';

    protected ?string $noteId = null;

    /**
     * Bind this collection to a note.
     */
    public function forNote(Note|string $note): static
    {
        $this->noteId = $note instanceof Note ? $note->id() : $note;
        return $this;
    }

    public function noteId(): ?string
    {
        return $this->noteId;
    }

    /**
     * Pre-fill from a transcript that arrived inline with the note, marking the
     * collection complete so nothing is fetched.
     *
     * @param list<TranscriptItem|array<string, mixed>> $items
     */
    public function seed(array $items): static
    {
        $this->data = [];
        $this->idIndex = [];

        foreach ($items as $item) {
            if ($item instanceof TranscriptItem) {
                $this->push($item);
            } elseif (is_array($item)) {
                $this->push($this->makeResource($item));
            }
        }

        $this->fetched = true;
        $this->hasMore = false;
        $this->cursor = null;

        return $this;
    }

    /**
     * The loaded transcript as text, one line per utterance.
     */
    public function toText(bool $withSpeakers = true, string $separator = "\n"): string
    {
        $lines = [];
        foreach ($this->all() as $item) {
            if ($item instanceof TranscriptItem) {
                $lines[] = $withSpeakers ? $item->toLine() : $item->text();
            }
        }
        return implode($separator, $lines);
    }

    /**
     * Every loaded utterance spoken by the note's owner.
     *
     * @return list<TranscriptItem>
     */
    public function fromMe(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn ($item): bool => $item instanceof TranscriptItem && $item->isMe()
        ));
    }

    /**
     * Distinct speaker labels seen in the loaded transcript.
     *
     * @return list<string>
     */
    public function speakerLabels(): array
    {
        $labels = [];
        foreach ($this->all() as $item) {
            if ($item instanceof TranscriptItem) {
                $labels[$item->speaker()?->label() ?? 'unknown'] = true;
            }
        }
        return array_keys($labels);
    }

    // ── Typed accessors ─────────────────────────────────────────────────

    /**
     * @return list<TranscriptItem>
     */
    public function all(): array
    {
        /** @var list<TranscriptItem> */
        return parent::all();
    }

    public function first(): ?TranscriptItem
    {
        $item = parent::first();
        return $item instanceof TranscriptItem ? $item : null;
    }

    public function last(): ?TranscriptItem
    {
        $item = parent::last();
        return $item instanceof TranscriptItem ? $item : null;
    }

    public function current(): TranscriptItem
    {
        /** @var TranscriptItem */
        return parent::current();
    }

    public function offsetGet(mixed $offset): ?TranscriptItem
    {
        $item = parent::offsetGet($offset);
        return $item instanceof TranscriptItem ? $item : null;
    }

    /**
     * @return \Generator<int, TranscriptItem>
     */
    public function each(): \Generator
    {
        yield from parent::each();
    }

    protected function endpoint(): string
    {
        if ($this->noteId === null) {
            throw new \LogicException(
                'TranscriptCollection has no note. Use Note::transcript(), or call forNote($noteId).'
            );
        }
        return Request::path(Note::API_PATH, $this->noteId, 'transcript');
    }
}
