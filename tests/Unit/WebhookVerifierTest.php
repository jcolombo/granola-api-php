<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Tests\Unit;

use Jcolombo\GranolaApiPhp\Configuration;
use Jcolombo\GranolaApiPhp\Exception\ConfigurationException;
use Jcolombo\GranolaApiPhp\Exception\SignatureVerificationException;
use Jcolombo\GranolaApiPhp\Tests\Support\GranolaTestCase;
use Jcolombo\GranolaApiPhp\Webhook\WebhookHeaders;
use Jcolombo\GranolaApiPhp\Webhook\WebhookVerifier;

final class WebhookVerifierTest extends GranolaTestCase
{
    private const BODY = '{"event_id":"evt_01","event_type":"note.generated","note_id":"not_1d3tmYTlCICgjy"}';

    /**
     * A fabricated signing secret, assembled at runtime.
     *
     * Standard Webhooks borrowed Stripe's `whsec_` prefix, so a literal
     * `whsec_<base64>` in source trips GitHub secret scanning as a "Stripe
     * Webhook Signing Secret" — a false positive that costs a real alert every
     * time someone forks this. Building it keeps the prefix-stripping path
     * genuinely under test without writing the pattern down.
     */
    private static function secret(): string
    {
        return 'whsec_' . base64_encode('granola-sdk-test-secret-not-real');
    }

    public function testAGenuineDeliveryVerifies(): void
    {
        $verifier = new WebhookVerifier(self::secret());
        $headers = $this->sign($verifier, 'msg_01', time(), self::BODY);

        $verifier->verify(self::BODY, $headers);

        self::assertTrue($verifier->isValid(self::BODY, $headers));
    }

    public function testTheSignatureMatchesTheStandardWebhooksScheme(): void
    {
        // Computed independently of the SDK, straight from the spec:
        // base64(HMAC-SHA256("{id}.{timestamp}.{body}", base64_decode(secret without whsec_)))
        $key = base64_decode(substr(self::secret(), 6), true);
        $expected = base64_encode(hash_hmac('sha256', 'msg_01.1770000000.' . self::BODY, (string) $key, true));

        $verifier = new WebhookVerifier(self::secret());

        self::assertSame($expected, $verifier->sign('msg_01', 1770000000, self::BODY));
        self::assertSame('v1,' . $expected, $verifier->signatureHeader('msg_01', 1770000000, self::BODY));
    }

    public function testASecretWithoutTheWhsecPrefixWorksIdentically(): void
    {
        $withPrefix = new WebhookVerifier(self::secret());
        $withoutPrefix = new WebhookVerifier(substr(self::secret(), 6));

        self::assertSame(
            $withPrefix->sign('msg_01', 1770000000, self::BODY),
            $withoutPrefix->sign('msg_01', 1770000000, self::BODY)
        );
    }

    public function testATamperedBodyFails(): void
    {
        $verifier = new WebhookVerifier(self::secret());
        $headers = $this->sign($verifier, 'msg_01', time(), self::BODY);

        $tampered = str_replace('not_1d3tmYTlCICgjy', 'not_attackerIdxx', self::BODY);

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessageMatches('/No signature .* matched/');

        $verifier->verify($tampered, $headers);
    }

    public function testADifferentSecretFails(): void
    {
        $signer = new WebhookVerifier(self::secret());
        $headers = $this->sign($signer, 'msg_01', time(), self::BODY);

        $other = new WebhookVerifier('whsec_' . base64_encode('a-completely-different-secret'));

        self::assertFalse($other->isValid(self::BODY, $headers));
    }

    public function testReplayingTheSameBodyUnderADifferentIdFails(): void
    {
        $verifier = new WebhookVerifier(self::secret());
        $timestamp = time();
        $headers = $this->sign($verifier, 'msg_01', $timestamp, self::BODY);

        // The id is part of the signed content, so swapping it invalidates the signature.
        $replayed = array_merge($headers, [WebhookHeaders::ID => 'msg_02']);

        self::assertFalse($verifier->isValid(self::BODY, $replayed));
    }

    public function testAnOldTimestampIsRejectedOutsideTheTolerance(): void
    {
        $verifier = new WebhookVerifier(self::secret(), 300);
        $headers = $this->sign($verifier, 'msg_01', time() - 1000, self::BODY);

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessageMatches('/outside the 300s tolerance/');

        $verifier->verify(self::BODY, $headers);
    }

    public function testAFutureTimestampIsRejectedToo(): void
    {
        $verifier = new WebhookVerifier(self::secret(), 60);
        $headers = $this->sign($verifier, 'msg_01', time() + 600, self::BODY);

        self::assertFalse($verifier->isValid(self::BODY, $headers));
    }

    public function testAToleranceOfZeroStillAcceptsTheCurrentSecond(): void
    {
        $verifier = new WebhookVerifier(self::secret(), 0);
        $headers = $this->sign($verifier, 'msg_01', time(), self::BODY);

        self::assertTrue($verifier->isValid(self::BODY, $headers));
    }

    public function testAnyOneOfSeveralSignaturesIsEnough(): void
    {
        $verifier = new WebhookVerifier(self::secret());
        $timestamp = time();
        $real = $verifier->sign('msg_01', $timestamp, self::BODY);

        // Granola sends both the old and new signature during a secret rotation.
        $headers = [
            WebhookHeaders::ID => 'msg_01',
            WebhookHeaders::TIMESTAMP => (string) $timestamp,
            WebhookHeaders::SIGNATURE => 'v1,' . base64_encode('stale-signature') . ' v1,' . $real,
        ];

        self::assertTrue($verifier->isValid(self::BODY, $headers));
    }

    public function testAMissingHeaderNamesTheHeader(): void
    {
        $verifier = new WebhookVerifier(self::secret());

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessageMatches("/missing the required 'webhook-signature'/");

        $verifier->verify(self::BODY, [
            WebhookHeaders::ID => 'msg_01',
            WebhookHeaders::TIMESTAMP => (string) time(),
        ]);
    }

    public function testAMalformedSignatureHeaderIsRejected(): void
    {
        $verifier = new WebhookVerifier(self::secret());

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessageMatches('/malformed/');

        $verifier->verify(self::BODY, [
            WebhookHeaders::ID => 'msg_01',
            WebhookHeaders::TIMESTAMP => (string) time(),
            WebhookHeaders::SIGNATURE => 'not-a-versioned-signature',
        ]);
    }

    public function testAnUnusableSecretIsRejectedAtConstruction(): void
    {
        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessageMatches('/not valid base64/');

        new WebhookVerifier('whsec_@@@not-base64@@@');
    }

    public function testWithSecretFallsBackToConfiguration(): void
    {
        Configuration::set('webhook.signingSecret', self::secret());
        Configuration::set('webhook.toleranceSeconds', 60);

        $verifier = WebhookVerifier::withSecret();
        $headers = $this->sign($verifier, 'msg_01', time(), self::BODY);

        self::assertTrue($verifier->isValid(self::BODY, $headers));
    }

    public function testWithSecretThrowsWhenNothingIsConfigured(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/No webhook signing secret/');

        WebhookVerifier::withSecret();
    }

    public function testHeadersCanComeFromServerGlobals(): void
    {
        $verifier = new WebhookVerifier(self::secret());
        $timestamp = time();
        $signed = $this->sign($verifier, 'msg_01', $timestamp, self::BODY);

        $server = [
            'REQUEST_METHOD' => 'POST',
            'HTTP_WEBHOOK_ID' => $signed[WebhookHeaders::ID],
            'HTTP_WEBHOOK_TIMESTAMP' => $signed[WebhookHeaders::TIMESTAMP],
            'HTTP_WEBHOOK_SIGNATURE' => $signed[WebhookHeaders::SIGNATURE],
        ];

        self::assertTrue($verifier->isValid(self::BODY, WebhookHeaders::fromGlobals($server)));
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $verifier = new WebhookVerifier(self::secret());
        $timestamp = time();

        $headers = WebhookHeaders::fromArray([
            'Webhook-Id' => 'msg_01',
            'WEBHOOK-TIMESTAMP' => [(string) $timestamp],
            'webhook-signature' => $verifier->signatureHeader('msg_01', $timestamp, self::BODY),
        ]);

        self::assertTrue($verifier->isValid(self::BODY, $headers));
    }

    /**
     * @return array<string, string>
     */
    private function sign(WebhookVerifier $verifier, string $id, int $timestamp, string $body): array
    {
        return [
            WebhookHeaders::ID => $id,
            WebhookHeaders::TIMESTAMP => (string) $timestamp,
            WebhookHeaders::SIGNATURE => $verifier->signatureHeader($id, $timestamp, $body),
        ];
    }
}
