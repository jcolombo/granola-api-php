<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Webhook;

use Jcolombo\GranolaApiPhp\Configuration;
use Jcolombo\GranolaApiPhp\Exception\ConfigurationException;
use Jcolombo\GranolaApiPhp\Exception\SignatureVerificationException;

/**
 * Standard Webhooks signature verification for Granola deliveries.
 *
 * The scheme, exactly:
 *
 *   1. Signed content is `{webhook-id}.{webhook-timestamp}.{raw body}`.
 *   2. The key is the signing secret with its `whsec_` prefix stripped, then
 *      base64-decoded to raw bytes.
 *   3. The signature is base64(HMAC-SHA256(signed content, key)).
 *   4. `webhook-signature` may carry several space-separated `v1,<sig>` entries
 *      during a secret rotation; a match on any one of them passes.
 *
 * Verify against the **raw** request body, byte for byte. Re-encoding a decoded
 * payload changes key order and whitespace and will never match.
 *
 * @override OVERRIDE-009
 */
final class WebhookVerifier
{
    private const SECRET_PREFIX = 'whsec_';

    private const SIGNATURE_VERSION = 'v1';

    /** @var non-empty-string raw HMAC key bytes */
    private readonly string $key;

    /**
     * @param string $signingSecret   The `whsec_…` secret from Create Webhook Endpoint.
     * @param int    $toleranceSeconds How far the delivery timestamp may drift from now.
     *
     * @throws SignatureVerificationException when the secret is not valid base64
     */
    public function __construct(
        string $signingSecret,
        private readonly int $toleranceSeconds = 300,
    ) {
        $this->key = self::decodeSecret($signingSecret);
    }

    /**
     * Build a verifier, falling back to `webhook.signingSecret` and
     * `webhook.toleranceSeconds` from configuration.
     *
     * @throws ConfigurationException when no secret is available
     */
    public static function withSecret(?string $signingSecret = null, ?int $toleranceSeconds = null): self
    {
        $signingSecret ??= Configuration::get('webhook.signingSecret');

        if (!is_string($signingSecret) || trim($signingSecret) === '') {
            throw ConfigurationException::missingSigningSecret();
        }

        return new self(
            trim($signingSecret),
            $toleranceSeconds ?? (int) Configuration::get('webhook.toleranceSeconds', 300)
        );
    }

    /**
     * Verify a delivery, or throw.
     *
     * @param array<string, string|list<string>>|WebhookHeaders $headers
     *
     * @throws SignatureVerificationException
     */
    public function verify(string $rawBody, array|WebhookHeaders $headers): void
    {
        $headers = WebhookHeaders::fromAny($headers);

        $id = $headers->mustGet(WebhookHeaders::ID);
        $timestamp = $headers->mustGet(WebhookHeaders::TIMESTAMP);
        $signatureHeader = $headers->mustGet(WebhookHeaders::SIGNATURE);

        $this->assertTimestampFresh($timestamp);

        $expected = $this->sign($id, $timestamp, $rawBody);

        foreach (self::extractSignatures($signatureHeader) as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return;
            }
        }

        throw SignatureVerificationException::noMatchingSignature();
    }

    /**
     * Verify without throwing.
     *
     * @param array<string, string|list<string>>|WebhookHeaders $headers
     */
    public function isValid(string $rawBody, array|WebhookHeaders $headers): bool
    {
        try {
            $this->verify($rawBody, $headers);
            return true;
        } catch (SignatureVerificationException) {
            return false;
        }
    }

    /**
     * Produce the base64 signature for a delivery.
     *
     * Exposed so a test suite or a replay tool can build a correctly signed
     * request without duplicating the scheme.
     */
    public function sign(string $id, string|int $timestamp, string $rawBody): string
    {
        $signedContent = $id . '.' . $timestamp . '.' . $rawBody;
        return base64_encode(hash_hmac('sha256', $signedContent, $this->key, true));
    }

    /**
     * The full `webhook-signature` header value for a delivery.
     */
    public function signatureHeader(string $id, string|int $timestamp, string $rawBody): string
    {
        return self::SIGNATURE_VERSION . ',' . $this->sign($id, $timestamp, $rawBody);
    }

    // ── Internals ───────────────────────────────────────────────────────

    /**
     * @throws SignatureVerificationException
     */
    private function assertTimestampFresh(string $timestamp): void
    {
        if (!is_numeric($timestamp)) {
            throw SignatureVerificationException::malformedSignature();
        }

        $skew = abs(time() - (int) $timestamp);
        if ($skew > $this->toleranceSeconds) {
            throw SignatureVerificationException::timestampOutOfTolerance($skew, $this->toleranceSeconds);
        }
    }

    /**
     * Pull the base64 payloads out of a possibly multi-signature header.
     *
     * @return list<string>
     */
    private static function extractSignatures(string $header): array
    {
        $signatures = [];

        foreach (preg_split('/\s+/', trim($header)) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }
            $parts = explode(',', $entry, 2);
            if (count($parts) !== 2 || $parts[0] !== self::SIGNATURE_VERSION || $parts[1] === '') {
                continue;
            }
            $signatures[] = $parts[1];
        }

        if ($signatures === []) {
            throw SignatureVerificationException::malformedSignature();
        }

        return $signatures;
    }

    /**
     * @return non-empty-string
     *
     * @throws SignatureVerificationException
     */
    private static function decodeSecret(string $signingSecret): string
    {
        $secret = $signingSecret;
        if (str_starts_with($secret, self::SECRET_PREFIX)) {
            $secret = substr($secret, strlen(self::SECRET_PREFIX));
        }

        $decoded = base64_decode($secret, true);
        if ($decoded === false || $decoded === '') {
            throw SignatureVerificationException::malformedSecret();
        }

        return $decoded;
    }
}
