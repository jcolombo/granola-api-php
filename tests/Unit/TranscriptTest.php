<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Tests\Unit;

use Jcolombo\GranolaApiPhp\Entity\Collection\TranscriptCollection;
use Jcolombo\GranolaApiPhp\Entity\Resource\Note;
use Jcolombo\GranolaApiPhp\Entity\Resource\TranscriptItem;
use Jcolombo\GranolaApiPhp\Enum\SpeakerAttribution;
use Jcolombo\GranolaApiPhp\Enum\SpeakerSource;
use Jcolombo\GranolaApiPhp\Tests\Support\GranolaTestCase;
use Jcolombo\GranolaApiPhp\Tests\Support\MockApi;

final class TranscriptTest extends GranolaTestCase
{
    public function testATranscriptThatWasNotInlinedPagesFromItsOwnEndpoint(): void
    {
        $api = MockApi::make(
            MockApi::fixture('note.get'),
            MockApi::fixture('transcript.page1'),
            MockApi::fixture('transcript.page2'),
        );

        $note = Note::find('not_1d3tmYTlCICgjy', false, $api->granola);
        $transcript = $note->transcript()->fetchAll();

        self::assertCount(3, $transcript);
        self::assertSame('/v1/notes/not_1d3tmYTlCICgjy/transcript', $api->pathAt(1));
        self::assertSame('50', $api->queryAt(1)['page_size'] ?? null);
    }

    public function testTranscriptItemsCarryTypedSpeakersAndTimes(): void
    {
        $api = MockApi::make(MockApi::fixture('note.get'), MockApi::fixture('transcript.page1'));

        $items = Note::find('not_1d3tmYTlCICgjy', false, $api->granola)->transcript()->fetch()->all();

        $first = $items[0];
        self::assertInstanceOf(TranscriptItem::class, $first);
        self::assertSame(SpeakerSource::Microphone, $first->speaker()?->source);
        self::assertSame(SpeakerAttribution::Me, $first->speaker()?->attribution);
        self::assertTrue($first->isMe());
        self::assertSame(3, $first->durationSeconds());
    }

    public function testAnAnonymousDiarizationLabelIsUsedWhenThereIsNoAttribution(): void
    {
        $api = MockApi::make(MockApi::fixture('note.get'), MockApi::fixture('transcript.page1'));

        $items = Note::find('not_1d3tmYTlCICgjy', false, $api->granola)->transcript()->fetch()->all();

        $second = $items[1];
        self::assertNull($second->speaker()?->attribution);
        self::assertSame('Speaker A', $second->speaker()?->label());
        self::assertFalse($second->isMe());
    }

    public function testTheSpeakerLabelPrefersAResolvedName(): void
    {
        $api = MockApi::make(MockApi::fixture('note.get.with-transcript'));

        $items = Note::find('not_1d3tmYTlCICgjy', true, $api->granola)->transcript()->all();

        self::assertSame('Raisin Patel', $items[1]->speaker()?->label());
        self::assertSame('Raisin Patel: Finally. Regular yoghurt is just milk that gave up halfway.', (string) $items[1]);
    }

    public function testToTextCanOmitSpeakers(): void
    {
        $api = MockApi::make(MockApi::fixture('note.get.with-transcript'));

        $transcript = Note::find('not_1d3tmYTlCICgjy', true, $api->granola)->transcript();

        self::assertStringNotContainsString('Raisin Patel:', $transcript->toText(false));
        self::assertStringContainsString('Regular yoghurt', $transcript->toText(false));
    }

    public function testSpeakerHelpersSummariseALoadedTranscript(): void
    {
        $api = MockApi::make(MockApi::fixture('note.get.with-transcript'));

        $transcript = Note::find('not_1d3tmYTlCICgjy', true, $api->granola)->transcript();

        self::assertCount(1, $transcript->fromMe());
        self::assertSame(['me', 'Raisin Patel'], $transcript->speakerLabels());
    }

    public function testAnUnboundTranscriptCollectionRefusesToGuessTheNote(): void
    {
        $api = MockApi::make(MockApi::fixture('transcript.page1'));

        $collection = new TranscriptCollection(TranscriptItem::class, $api->granola);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/has no note/');

        $collection->fetch();
    }
}
