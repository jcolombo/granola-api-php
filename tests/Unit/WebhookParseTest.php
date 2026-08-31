<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Tests\Unit;

use Jcolombo\GranolaApiPhp\Enum\WebhookEventType;
use Jcolombo\GranolaApiPhp\Exception\SignatureVerificationException;
use Jcolombo\GranolaApiPhp\Exception\WebhookPayloadException;
use Jcolombo\GranolaApiPhp\Tests\Support\GranolaTestCase;
use Jcolombo\GranolaApiPhp\Tests\Support\MockApi;
use Jcolombo\GranolaApiPhp\Webhook\Webhook;
use Jcolombo\GranolaApiPhp\Webhook\WebhookHeaders;
use Jcolombo\GranolaApiPhp\Webhook\WebhookVerifier;

final class WebhookParseTest extends GranolaTestCase
{
    /**
     * A fabricated signing secret, assembled at runtime so that a literal
     * `whsec_<base64>` never appears in source — see WebhookVerifierTest.
     */
    private static function secret(): string
    {
        return 'whsec_' . base64_encode('granola-sdk-test-secret-not-real');
    }

    public function testAVerifiedDeliveryBecomesATypedEvent(): void
    {
        $body = $this->payload('note.generated');
        $event = Webhook::parse($body, $this->headersFor($body), self::secret());

        self::assertSame('evt_01HXYZ', $event->eventId);
        self::assertSame(WebhookEventType::NoteGenerated, $event->type);
        self::assertSame('note.generated', $event->rawType);
        self::assertSame('not_1d3tmYTlCICgjy', $event->noteId);
        self::assertSame('2026-08-31T09:15:00+00:00', $event->occurredAt?->format(DATE_ATOM));
        self::assertTrue($event->isGenerated());
        self::assertFalse($event->isEdited());
    }

    public function testAnEditedEventExposesItsChangedFields(): void
    {
        $body = $this->payload('note.edited', ['data' => ['changed_fields' => ['summary']]]);
        $event = Webhook::parse($body, $this->headersFor($body), self::secret());

        self::assertTrue($event->isEdited());
        self::assertSame(['summary'], $event->changedFields);
        self::assertTrue($event->changed('summary'));
        self::assertFalse($event->changed('title'));
    }

    public function testAnUnknownEventTypeParsesInsteadOfBreaking(): void
    {
        // Granola adds events over time; a receiver must survive the next one.
        $body = $this->payload('note.archived');
        $event = Webhook::parse($body, $this->headersFor($body), self::secret());

        self::assertNull($event->type);
        self::assertSame('note.archived', $event->rawType);
        self::assertTrue($event->isUnknownType());
        self::assertTrue($event->is('note.archived'));
        self::assertSame('not_1d3tmYTlCICgjy', $event->noteId);
    }

    public function testParsingRefusesAnInvalidSignature(): void
    {
        $body = $this->payload('note.generated');
        $headers = $this->headersFor($body);
        $tampered = str_replace('not_1d3tmYTlCICgjy', 'not_attackerIdxx', $body);

        $this->expectException(SignatureVerificationException::class);

        Webhook::parse($tampered, $headers, self::secret());
    }

    public function testMalformedJsonIsReportedClearly(): void
    {
        $body = '{"event_id": ';

        $this->expectException(WebhookPayloadException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        Webhook::parse($body, $this->headersFor($body), self::secret());
    }

    public function testAMissingRequiredFieldNamesTheField(): void
    {
        $body = (string) json_encode(['event_id' => 'evt_01', 'event_type' => 'note.generated']);

        $this->expectException(WebhookPayloadException::class);
        $this->expectExceptionMessageMatches("/missing the required 'note_id'/");

        Webhook::parse($body, $this->headersFor($body), self::secret());
    }

    public function testTheNoteIsNotFetchedUntilAHandlerAsksForIt(): void
    {
        $api = MockApi::make(MockApi::fixture('note.get'));
        $body = $this->payload('note.generated');

        $event = Webhook::parse($body, $this->headersFor($body), self::secret(), $api->granola);

        self::assertSame(0, $api->requestCount(), 'parsing alone must not call the API');

        $note = $event->note();

        self::assertSame(1, $api->requestCount());
        self::assertSame('Quarterly yoghurt budget review', $note->title);
    }

    public function testRepeatedNoteCallsAreMemoised(): void
    {
        $api = MockApi::make(MockApi::fixture('note.get'));
        $body = $this->payload('note.generated');
        $event = Webhook::parse($body, $this->headersFor($body), self::secret(), $api->granola);

        $event->note();
        $event->note();
        $event->note();

        self::assertSame(1, $api->requestCount());
    }

    public function testAskingForTheTranscriptAfterAPlainFetchRefetchesOnce(): void
    {
        $api = MockApi::make(
            MockApi::fixture('note.get'),
            MockApi::fixture('note.get.with-transcript'),
        );
        $body = $this->payload('note.generated');
        $event = Webhook::parse($body, $this->headersFor($body), self::secret(), $api->granola);

        $event->note();
        $withTranscript = $event->note(true);

        self::assertSame(2, $api->requestCount());
        self::assertTrue($withTranscript->hasInlineTranscript());
        self::assertSame(['include' => 'transcript'], $api->queryAt(1));
    }

    public function testAnEventWithoutAConnectionSaysSoInsteadOfFailingObscurely(): void
    {
        $body = $this->payload('note.generated');
        $event = Webhook::parse($body, $this->headersFor($body), self::secret());

        self::assertFalse($event->hasConnection());
        $this->expectException(WebhookPayloadException::class);
        $this->expectExceptionMessageMatches('/no Granola connection/');

        $event->note();
    }

    public function testAConnectionCanBeAttachedAfterParsing(): void
    {
        $api = MockApi::make(MockApi::fixture('note.get'));
        $body = $this->payload('note.generated');

        $event = Webhook::parse($body, $this->headersFor($body), self::secret());
        $connected = $event->withConnection($api->granola);

        self::assertFalse($event->hasConnection(), 'the original event is untouched');
        self::assertTrue($connected->hasConnection());
        self::assertSame('not_1d3tmYTlCICgjy', $connected->note()->id());
    }

    public function testTheDeliveryIdIsReadableBeforeVerification(): void
    {
        $body = $this->payload('note.generated');
        $headers = $this->headersFor($body, 'msg_dedupe_key');

        self::assertSame('msg_dedupe_key', Webhook::deliveryId($headers));
    }

    public function testIsValidAnswersWithoutThrowing(): void
    {
        $body = $this->payload('note.generated');

        self::assertTrue(Webhook::isValid($body, $this->headersFor($body), self::secret()));
        self::assertFalse(Webhook::isValid($body . ' ', $this->headersFor($body), self::secret()));
    }

    public function testTheOriginalPayloadSurvivesForStorageAndReplay(): void
    {
        $body = $this->payload('note.edited', ['data' => ['changed_fields' => ['summary']]]);
        $event = Webhook::parse($body, $this->headersFor($body), self::secret());

        $stored = (string) json_encode($event);
        $replayed = Webhook::parseUnverified($stored);

        self::assertSame($event->eventId, $replayed->eventId);
        self::assertSame($event->changedFields, $replayed->changedFields);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $extra
     */
    private function payload(string $type, array $extra = []): string
    {
        return (string) json_encode(array_merge([
            'event_id' => 'evt_01HXYZ',
            'event_type' => $type,
            'note_id' => 'not_1d3tmYTlCICgjy',
            'occurred_at' => '2026-08-31T09:15:00Z',
        ], $extra));
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(string $body, string $id = 'evt_01HXYZ'): array
    {
        $verifier = new WebhookVerifier(self::secret());
        $timestamp = time();

        return [
            WebhookHeaders::ID => $id,
            WebhookHeaders::TIMESTAMP => (string) $timestamp,
            WebhookHeaders::SIGNATURE => $verifier->signatureHeader($id, $timestamp, $body),
        ];
    }
}
