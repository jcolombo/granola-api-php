<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Tests\Unit;

use Jcolombo\GranolaApiPhp\Entity\Collection\NoteCollection;
use Jcolombo\GranolaApiPhp\Entity\Resource\Note;
use Jcolombo\GranolaApiPhp\Tests\Support\GranolaTestCase;
use Jcolombo\GranolaApiPhp\Tests\Support\MockApi;

final class CursorPaginationTest extends GranolaTestCase
{
    public function testFetchReadsExactlyOnePage(): void
    {
        $api = MockApi::make(MockApi::fixture('notes.list.page1'));

        $notes = Note::list($api->granola)->fetch();

        self::assertCount(2, $notes);
        self::assertSame(1, $api->requestCount());
        self::assertTrue($notes->hasMore());
        self::assertSame('eyJvZmZzZXQiOjJ9', $notes->cursor());
    }

    public function testFetchAllFollowsTheCursorEvenWhenAPageIsNotFull(): void
    {
        // page1 holds 2 notes at a page size of 10 but still reports hasMore —
        // exactly the case Granola warns about, and the reason paging must never
        // be driven by counting results.
        $api = MockApi::make(
            MockApi::fixture('notes.list.page1'),
            MockApi::fixture('notes.list.page2'),
        );

        $notes = Note::list($api->granola)->fetchAll();

        self::assertCount(3, $notes);
        self::assertSame(2, $api->requestCount());
        self::assertFalse($notes->hasMore());
        self::assertNull($notes->cursor());
    }

    public function testTheSecondRequestCarriesTheCursorFromTheFirst(): void
    {
        $api = MockApi::make(
            MockApi::fixture('notes.list.page1'),
            MockApi::fixture('notes.list.page2'),
        );

        Note::list($api->granola)->fetchAll();

        self::assertArrayNotHasKey('cursor', $api->queryAt(0));
        self::assertSame('eyJvZmZzZXQiOjJ9', $api->queryAt(1)['cursor'] ?? null);
    }

    public function testEachStreamsEveryItemWhileHoldingOnePageAtATime(): void
    {
        $api = MockApi::make(
            MockApi::fixture('notes.list.page1'),
            MockApi::fixture('notes.list.page2'),
        );

        $collection = Note::list($api->granola);
        $titles = [];
        foreach ($collection->each() as $note) {
            $titles[] = $note->title;
        }

        self::assertCount(3, $titles);
        self::assertSame(2, $api->requestCount());
        self::assertCount(1, $collection, 'only the final page is still resident');
    }

    public function testACursorCanBeStoredAndResumedLater(): void
    {
        $first = MockApi::make(MockApi::fixture('notes.list.page1'));
        $cursor = Note::list($first->granola)->fetch()->cursor();

        $second = MockApi::make(MockApi::fixture('notes.list.page2'));
        $resumed = Note::list($second->granola)->withCursor($cursor)->fetch();

        self::assertSame($cursor, $second->queryAt(0)['cursor'] ?? null);
        self::assertCount(1, $resumed);
    }

    public function testMaxPagesBoundsAnAccidentalRunaway(): void
    {
        // Every page claims there is another one; without a bound this never ends.
        $endless = MockApi::make(
            MockApi::fixture('notes.list.page1'),
            MockApi::fixture('notes.list.page1'),
            MockApi::fixture('notes.list.page1'),
        );

        $notes = Note::list($endless->granola)->maxPages(3)->fetchAll();

        self::assertSame(3, $endless->requestCount());
        self::assertCount(6, $notes);
    }

    public function testPageSizeDefaultsFromConfigurationAndCanBeOverridden(): void
    {
        $api = MockApi::make(
            MockApi::fixture('notes.list.page2'),
            MockApi::fixture('notes.list.page2'),
        );

        Note::list($api->granola)->fetch();
        self::assertSame('10', $api->queryAt(0)['page_size'] ?? null);

        Note::list($api->granola)->pageSize(30)->fetch();
        self::assertSame('30', $api->queryAt(1)['page_size'] ?? null);
    }

    public function testFiltersAreSentAsQueryParameters(): void
    {
        $api = MockApi::make(MockApi::fixture('notes.list.page2'));

        Note::list($api->granola)
            ->updatedAfter('2026-08-01')
            ->createdBefore(new \DateTimeImmutable('2026-08-31T00:00:00+00:00'))
            ->inFolder('fol_4y6LduVdwSKC27')
            ->fetch();

        $query = $api->queryAt(0);
        self::assertSame('2026-08-01', $query['updated_after']);
        self::assertSame('2026-08-31T00:00:00+00:00', $query['created_before']);
        self::assertSame('fol_4y6LduVdwSKC27', $query['folder_id']);
    }

    public function testCollectionAccessorsWorkOnLoadedResults(): void
    {
        $api = MockApi::make(MockApi::fixture('notes.list.page1'));

        $notes = Note::list($api->granola)->fetch();

        self::assertInstanceOf(NoteCollection::class, $notes);
        self::assertFalse($notes->isEmpty());
        self::assertSame('not_1d3tmYTlCICgjy', $notes->first()?->id());
        self::assertSame('not_2f4unZUmDJDhkz', $notes->last()?->id());
        self::assertSame('Cultures and starters sync', $notes->find('not_2f4unZUmDJDhkz')?->title);
        self::assertSame('Cultures and starters sync', $notes['not_2f4unZUmDJDhkz']?->title);
        self::assertContains('Quarterly yoghurt budget review', $notes->flatten('title'));
    }

    public function testAFailedPageLeavesTheCollectionEmptyAndInspectable(): void
    {
        $api = MockApi::make(MockApi::json(['message' => 'Bad request'], 400));

        $notes = Note::list($api->granola)->fetchAll();

        self::assertCount(0, $notes);
        self::assertFalse($notes->hasMore(), 'a failure must not loop forever');
        self::assertSame(400, $notes->lastResponse()?->responseCode);
    }
}
