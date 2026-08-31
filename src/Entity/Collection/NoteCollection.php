<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Entity\Collection;

use Jcolombo\GranolaApiPhp\Entity\AbstractCollection;
use Jcolombo\GranolaApiPhp\Entity\Resource\Folder;
use Jcolombo\GranolaApiPhp\Entity\Resource\Note;

/**
 * A cursor-paginated list of notes.
 *
 *     $notes = Note::list()
 *         ->updatedAfter('2026-08-01')
 *         ->inFolder('fol_4y6LduVdwSKC27')
 *         ->pageSize(30);
 *
 *     foreach ($notes->each() as $note) { ... }
 *
 * List responses carry the summary shape only — id, object, title, owner,
 * created_at, updated_at. Summaries, attendees, calendar event and transcript
 * arrive with Note::find(), so a listing is cheap to walk and each note is
 * fetched individually only when it is actually needed.
 *
 * Granola caps page_size at 30 here, and only returns notes that already have a
 * generated AI summary and transcript.
 */
class NoteCollection extends AbstractCollection
{
    public const RESULT_KEY = 'notes';

    protected const PAGE_SIZE_CONFIG = 'notesPageSize';

    /**
     * Only notes created strictly before this moment.
     */
    public function createdBefore(string|\DateTimeInterface $when): static
    {
        return $this->filter('created_before', $when);
    }

    /**
     * Only notes created strictly after this moment.
     */
    public function createdAfter(string|\DateTimeInterface $when): static
    {
        return $this->filter('created_after', $when);
    }

    /**
     * Only notes updated after this moment — the filter an incremental sync wants.
     */
    public function updatedAfter(string|\DateTimeInterface $when): static
    {
        return $this->filter('updated_after', $when);
    }

    /**
     * Restrict to one folder. Child folders are included automatically.
     */
    public function inFolder(Folder|string $folder): static
    {
        $id = $folder instanceof Folder ? (string) $folder->id() : $folder;
        return $this->filter('folder_id', $id);
    }

    /**
     * Notes created between two moments, inclusive of neither bound.
     */
    public function createdBetween(string|\DateTimeInterface $from, string|\DateTimeInterface $to): static
    {
        return $this->createdAfter($from)->createdBefore($to);
    }

    // ── Typed accessors ─────────────────────────────────────────────────

    /**
     * @return list<Note>
     */
    public function all(): array
    {
        /** @var list<Note> */
        return parent::all();
    }

    public function first(): ?Note
    {
        $note = parent::first();
        return $note instanceof Note ? $note : null;
    }

    public function last(): ?Note
    {
        $note = parent::last();
        return $note instanceof Note ? $note : null;
    }

    public function find(string $id): ?Note
    {
        $note = parent::find($id);
        return $note instanceof Note ? $note : null;
    }

    public function current(): Note
    {
        /** @var Note */
        return parent::current();
    }

    public function offsetGet(mixed $offset): ?Note
    {
        $note = parent::offsetGet($offset);
        return $note instanceof Note ? $note : null;
    }

    /**
     * @return \Generator<int, Note>
     */
    public function each(): \Generator
    {
        yield from parent::each();
    }
}
