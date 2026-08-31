<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Tests\Unit;

use Jcolombo\GranolaApiPhp\Entity\Resource\Note;
use Jcolombo\GranolaApiPhp\Tests\Support\GranolaTestCase;
use Jcolombo\GranolaApiPhp\Tests\Support\MockApi;

/**
 * fetchAll() is the "give me everything, stitched together" call. Whatever
 * shape the data arrived in, one call must leave the collection complete —
 * without the caller branching on how it got there, and without re-requesting
 * content that is already loaded.
 */
final class CompleteContentTest extends GranolaTestCase
{
    public function testFetchAllOnAnInlineTranscriptMakesNoExtraRequest(): void
    {
        // The whole point of asking for the transcript inline is to avoid the
        // second call. fetchAll() must not undo that.
        $api = MockApi::make(MockApi::fixture('note.get.with-transcript'));

        $transcript = Note::find('not_1d3tmYTlCICgjy', true, $api->granola)
            ->transcript()
            ->fetchAll();

        self::assertSame(1, $api->requestCount(), 'the note fetch is the only request needed');
        self::assertCount(2, $transcript);
        self::assertStringContainsString('Greek is the only yoghurt', $transcript->toText());
    }

    public function testTheSameCallCompletesATranscriptThatDidNotArriveInline(): void
    {
        // Identical call, transcript delivered by pages instead. The caller
        // should not have to know which case they are in.
        $api = MockApi::make(
            MockApi::fixture('note.get'),
            MockApi::fixture('transcript.page1'),
            MockApi::fixture('transcript.page2'),
        );

        $transcript = Note::find('not_1d3tmYTlCICgjy', false, $api->granola)
            ->transcript()
            ->fetchAll();

        self::assertCount(3, $transcript);
        self::assertSame(3, $api->requestCount());
    }

    public function testTheSameCallCompletesAfterA413Fallback(): void
    {
        // Granola refused to inline the transcript, the SDK recovered, and the
        // caller still writes exactly the same line.
        $api = MockApi::make(
            MockApi::json(['code' => 'TRANSCRIPT_TOO_LARGE'], 413),
            MockApi::fixture('note.get'),
            MockApi::fixture('transcript.page1'),
            MockApi::fixture('transcript.page2'),
        );

        $note = Note::find('not_1d3tmYTlCICgjy', true, $api->granola);
        $transcript = $note->transcript()->fetchAll();

        self::assertTrue($note->transcriptWasTooLarge());
        self::assertCount(3, $transcript);
    }

    public function testFetchAllIsIdempotent(): void
    {
        $api = MockApi::make(
            MockApi::fixture('notes.list.page1'),
            MockApi::fixture('notes.list.page2'),
        );

        $notes = Note::list($api->granola)->fetchAll();
        $countAfterFirst = count($notes);
        $requestsAfterFirst = $api->requestCount();

        $notes->fetchAll();

        self::assertSame($countAfterFirst, count($notes), 'a second call must not duplicate or drop items');
        self::assertSame($requestsAfterFirst, $api->requestCount(), 'a complete collection must not re-request');
    }

    public function testRewindPagesReopensACompletedCollectionForRequerying(): void
    {
        // The escape hatch: fetchAll() short-circuits when complete, so changing
        // filters requires an explicit reset.
        $api = MockApi::make(
            MockApi::fixture('notes.list.page2'),
            MockApi::fixture('notes.list.page2'),
        );

        $notes = Note::list($api->granola)->fetchAll();
        self::assertSame(1, $api->requestCount());

        $notes->rewindPages()->updatedAfter('2026-08-01')->fetchAll();

        self::assertSame(2, $api->requestCount());
        self::assertSame('2026-08-01', $api->queryAt(1)['updated_after'] ?? null);
    }

    public function testACompletedCollectionStillReportsItselfComplete(): void
    {
        $api = MockApi::make(
            MockApi::fixture('notes.list.page1'),
            MockApi::fixture('notes.list.page2'),
        );

        $notes = Note::list($api->granola)->fetchAll();

        self::assertFalse($notes->hasMore(), 'nothing was left behind');
        self::assertNull($notes->cursor());
    }

    public function testEachAfterFetchDoesNotSkipTheAlreadyLoadedPage(): void
    {
        // each() resumes from the collection's cursor, which after fetch()
        // points at page 2 — so page 1 has to be yielded from what is loaded
        // rather than paged over.
        $api = MockApi::make(
            MockApi::fixture('notes.list.page1'),
            MockApi::fixture('notes.list.page2'),
        );

        $notes = Note::list($api->granola)->fetch();

        $ids = [];
        foreach ($notes->each() as $note) {
            $ids[] = $note->id();
        }

        self::assertSame([
            'not_1d3tmYTlCICgjy',
            'not_2f4unZUmDJDhkz',
            'not_3g5voAVnEKEilA',
        ], $ids, 'every note appears exactly once, in order');
        self::assertSame(2, $api->requestCount(), 'page 1 is reused, not re-requested');
    }

    public function testEachOnAnInlineTranscriptMakesNoExtraRequest(): void
    {
        $api = MockApi::make(MockApi::fixture('note.get.with-transcript'));

        $lines = [];
        foreach (Note::find('not_1d3tmYTlCICgjy', true, $api->granola)->transcript()->each() as $item) {
            $lines[] = $item->text();
        }

        self::assertCount(2, $lines);
        self::assertSame(1, $api->requestCount());
    }

    public function testEachStillHonoursAStoredCursorOnAFreshCollection(): void
    {
        $api = MockApi::make(MockApi::fixture('notes.list.page2'));

        $collected = [];
        foreach (Note::list($api->granola)->withCursor('eyJvZmZzZXQiOjJ9')->each() as $note) {
            $collected[] = $note->id();
        }

        self::assertSame(['not_3g5voAVnEKEilA'], $collected);
        self::assertSame('eyJvZmZzZXQiOjJ9', $api->queryAt(0)['cursor'] ?? null);
    }

    public function testHittingTheMaxPagesBoundIsDetectable(): void
    {
        // fetchAll() is bounded so a bad filter cannot loop forever. When the
        // bound truncates, hasMore() stays true — the caller can tell.
        $endless = MockApi::make(
            MockApi::fixture('notes.list.page1'),
            MockApi::fixture('notes.list.page1'),
        );

        $notes = Note::list($endless->granola)->maxPages(2)->fetchAll();

        self::assertTrue($notes->hasMore(), 'truncation must be visible, not silent');
        self::assertNotNull($notes->cursor(), 'and resumable from the cursor');
    }
}
