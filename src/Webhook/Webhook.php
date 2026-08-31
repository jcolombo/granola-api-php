<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Webhook;

use Jcolombo\GranolaApiPhp\Exception\SignatureVerificationException;
use Jcolombo\GranolaApiPhp\Exception\WebhookPayloadException;
use Jcolombo\GranolaApiPhp\Granola;

/**
 * Turn a raw Granola webhook POST into a verified, typed object.
 *
 * This package does not run your endpoint and does not dispatch to listeners —
 * routing, queueing, retries and deduplication belong to your application. What
 * it removes is the part every integration would otherwise reimplement: getting
 * the signature check exactly right, and parsing the payload into something
 * typed.
 *
 *     $event = Webhook::parse(
 *         file_get_contents('php://input'),
 *         WebhookHeaders::fromGlobals(),
 *         $signingSecret,
 *         Granola::connect(),
 *     );
 *
 *     // then hand $event to your own handler
 *     match (true) {
 *         $event->isGenerated() => $this->onGenerated($event),
 *         $event->isEdited()    => $this->onEdited($event),
 *         default               => null,
 *     };
 *
 * Two rules worth keeping:
 *
 *   - Verify the **raw** body. Frameworks that hand you a decoded array have
 *     already destroyed the byte sequence the signature covers.
 *   - Answer 2xx fast. Granola gives an endpoint 15 seconds and retries with
 *     exponential backoff for four days before disabling it, so queue the work
 *     and respond — do not fetch, transform and store inline.
 */
final class Webhook
{
    /**
     * Verify a delivery and parse it.
     *
     * @param string                                           $rawBody       The exact bytes Granola POSTed.
     * @param array<string, string|list<string>>|WebhookHeaders $headers      Delivery headers.
     * @param ?string                                          $signingSecret Defaults to `webhook.signingSecret`.
     * @param ?Granola                                         $connection    Enables $event->note().
     *
     * @throws SignatureVerificationException when the signature, timestamp or headers do not check out
     * @throws WebhookPayloadException        when the verified body is not a payload we understand
     */
    public static function parse(
        string $rawBody,
        array|WebhookHeaders $headers,
        ?string $signingSecret = null,
        ?Granola $connection = null,
    ): WebhookEvent {
        WebhookVerifier::withSecret($signingSecret)->verify($rawBody, $headers);

        return self::parseUnverified($rawBody, $connection);
    }

    /**
     * Parse a payload **without checking its signature**.
     *
     * For replaying stored deliveries and for tests. Never call this on live
     * inbound traffic: an unverified body is attacker-controlled, and anyone who
     * knows your endpoint URL can post one.
     *
     * @throws WebhookPayloadException
     */
    public static function parseUnverified(string $rawBody, ?Granola $connection = null): WebhookEvent
    {
        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw WebhookPayloadException::invalidJson($e->getMessage());
        }

        if (!is_array($payload)) {
            throw WebhookPayloadException::invalidJson('payload is not a JSON object');
        }

        return WebhookEvent::fromPayload($payload, $connection);
    }

    /**
     * Verify a delivery without parsing it.
     *
     * @param array<string, string|list<string>>|WebhookHeaders $headers
     *
     * @throws SignatureVerificationException
     */
    public static function verify(string $rawBody, array|WebhookHeaders $headers, ?string $signingSecret = null): void
    {
        WebhookVerifier::withSecret($signingSecret)->verify($rawBody, $headers);
    }

    /**
     * Verify without throwing — for endpoints that would rather answer 400 than
     * catch.
     *
     * @param array<string, string|list<string>>|WebhookHeaders $headers
     */
    public static function isValid(string $rawBody, array|WebhookHeaders $headers, ?string $signingSecret = null): bool
    {
        try {
            self::verify($rawBody, $headers, $signingSecret);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The delivery ID, straight from the headers.
     *
     * Granola retries a failed delivery for up to four days, and every retry
     * carries this same ID — so it is the key to deduplicate on, and it can be
     * read before the body is verified or parsed.
     *
     * @param array<string, string|list<string>>|WebhookHeaders $headers
     */
    public static function deliveryId(array|WebhookHeaders $headers): ?string
    {
        return WebhookHeaders::fromAny($headers)->get(WebhookHeaders::ID);
    }
}
