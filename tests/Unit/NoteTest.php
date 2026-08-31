<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Tests\Unit;

use Jcolombo\GranolaApiPhp\Configuration;
use Jcolombo\GranolaApiPhp\Entity\Resource\Note;
use Jcolombo\GranolaApiPhp\Entity\Value\CalendarEvent;
use Jcolombo\GranolaApiPhp\Entity\Value\User;
use Jcolombo\GranolaApiPhp\Exception\TranscriptTooLargeException;
use Jcolombo\GranolaApiPhp\Tests\Support\GranolaTestCase;
use Jcolombo\GranolaApiPhp\Tests\Support\MockApi;

final class NoteTest extends GranolaTestCase
{
    public function testFetchingANoteHydratesTypedProperties(): void
    {
        $api = MockApi::make(MockApi::fixture('note.get'));

        $note = Note::find('not_1d3tmYTlCICgjy', false, $api->granola);

        self::assertSame('not_1d3tmYTlCICgjy', $note->id());
        self::assertSame('Quarterly yoghurt budget review', $note->title);
        self::assertInstanceOf(\DateTimeImmutable::class, $note->created_at);
        self::assertSame('2026-01-27', $note->created_at->format('Y-m-d'));
        self::assertInstanceOf(User::class, $note->owner());
        self::assertSame('oat@granola.ai', $note->owner()->email);
        self::assertSame('/v1/notes/not_1d3tmYTlCICgjy', $api->pathAt(0));
    }

    public function testAttendeesAndFolderMembershipBecomeObjects(): void
    {
        $api = MockApi::make(MockApi::fixture('note.get'));

        $note = Note::find('not_1d3tmYTlCICgjy', false, $api->granola);

        self::assertSame(['oat@granola.ai', 'raisin@granola.ai'], $note->attendeeEmails());
        self::assertSame(['fol_4y6LduVdwSKC27'], $note->folderIds());
        self::assertTrue($note->isInFolder('fol_4y6LduVdwSKC27'));
        self::assertFalse($note->isInFolder('fol_nope000000000'));
        self::assertSame('Top secret recipes', $note->folders()[0]->name());
    }

    public function testCalendarEventIsParsedAndItsHelpersWork(): void
    {
        $api = MockApi::make(MockApi::fixture('note.get'));

        $event = Note::find('not_1d3tmYTlCICgjy', false, $api->granola)->calendarEvent();

        self::assertInstanceOf(CalendarEvent::class, $event);
        self::assertSame(60, $event->scheduledMinutes());
        self::assertSame(['raisin@granola.ai', 'buyer@example.com'], $event->inviteeEmails());
        self::assertTrue($event->isExternal(), 'buyer@example.com is outside the organiser domain');
    }

    public function testANoteWithoutACalendarEventReportsSoRatherThanFailing(): void
    {
        $api = MockApi::make(MockApi::fixture('note.get.with-transcript'));

        $note = Note::find('not_1d3tmYTlCICgjy', true, $api->granola);

        self::assertNull($note->calendarEvent());
        self::assertFalse($note->hasCalendarEvent());
    }

    public function testRequestingTheTranscriptAddsTheIncludeParameter(): void
    {
        $api = MockApi::make(MockApi::fixture('note.get.with-transcript'));

        Note::find('not_1d3tmYTlCICgjy', true, $api->granola);

        self::assertSame(['include' => 'transcript'], $api->queryAt(0));
    }

    public function testAnInlineTranscriptIsUsedWithoutASecondRequest(): void
    {
        $api = MockApi::make(MockApi::fixture('note.get.with-transcript'));

        $note = Note::find('not_1d3tmYTlCICgjy', true, $api->granola);
        $transcript = $note->transcript();

        self::assertTrue($note->hasInlineTranscript());
        self::assertCount(2, $transcript);
        self::assertSame(1, $api->requestCount(), 'the inline transcript must not trigger a fetch');
        self::assertStringContainsString('Greek is the only yoghurt', $transcript->toText());
    }

    public function testTranscriptTextRendersSpeakerLabels(): void
    {
        $api = MockApi::make(MockApi::fixture('note.get.with-transcript'));

        $text = Note::find('not_1d3tmYTlCICgjy', true, $api->granola)->transcriptText();

        self::assertStringContainsString('me: ', $text);
        self::assertStringContainsString('Raisin Patel: ', $text);
    }

    public function testATooLargeTranscriptFallsBackToASecondPlainFetch(): void
    {
        $api = MockApi::make(
            MockApi::json(['code' => 'TRANSCRIPT_TOO_LARGE'], 413),
            MockApi::fixture('note.get'),
        );

        $note = Note::find('not_1d3tmYTlCICgjy', true, $api->granola);

        self::assertTrue($note->transcriptWasTooLarge());
        self::assertFalse($note->hasInlineTranscript());
        self::assertSame('Quarterly yoghurt budget review', $note->title, 'the note still loaded');
        self::assertSame(2, $api->requestCount());
        self::assertSame([], $api->queryAt(1), 'the retry drops include=transcript');
    }

    public function testTheFallbackCanBeTurnedOffToGetAnException(): void
    {
        Configuration::set('notes.autoFallbackLargeTranscript', false);
        $api = MockApi::make(MockApi::json(['code' => 'TRANSCRIPT_TOO_LARGE'], 413));

        $this->expectException(TranscriptTooLargeException::class);

        Note::find('not_1d3tmYTlCICgjy', true, $api->granola);
    }

    public function testSummaryPrefersMarkdownAndFallsBackToText(): void
    {
        $withMarkdown = MockApi::make(MockApi::fixture('note.get'));
        self::assertStringStartsWith('## Quarterly', (string) Note::find('n', false, $withMarkdown->granola)->summary());

        $withoutMarkdown = MockApi::make(MockApi::fixture('note.get.with-transcript'));
        self::assertSame(
            'The quarterly yoghurt budget review was a success.',
            Note::find('n', false, $withoutMarkdown->granola)->summary()
        );
    }

    public function testFieldsTheSdkDoesNotModelAreKeptRatherThanDropped(): void
    {
        $api = MockApi::make(MockApi::json([
            'id' => 'not_1d3tmYTlCICgjy',
            'object' => 'note',
            'title' => 'Something new',
            'sentiment_score' => 0.82,
        ]));

        $note = Note::find('not_1d3tmYTlCICgjy', false, $api->granola);

        self::assertSame(['sentiment_score' => 0.82], $note->unmapped());
        self::assertSame(0.82, $note->get('sentiment_score'));
    }

    public function testAFailedFetchIsInspectableRatherThanSilent(): void
    {
        $api = MockApi::make(MockApi::json(['message' => 'Invalid API key'], 401));

        $note = Note::find('not_1d3tmYTlCICgjy', false, $api->granola);

        self::assertFalse($note->succeeded());
        self::assertSame(401, $note->lastResponse()?->responseCode);
        self::assertSame('Invalid API key', $note->lastResponse()?->errorMessage());
        self::assertNull($note->id());
    }
}
