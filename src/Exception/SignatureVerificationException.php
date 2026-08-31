<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Exception;

/**
 * A webhook delivery failed Standard Webhooks signature verification.
 *
 * Treat every one of these as hostile input: log it, respond 400, and do not
 * parse or act on the body.
 */
class SignatureVerificationException extends GranolaException
{
    public static function missingHeader(string $header): self
    {
        return new self("Webhook delivery is missing the required '{$header}' header.");
    }

    public static function malformedSignature(): self
    {
        return new self('Webhook signature header is malformed; expected "v1,<base64>".');
    }

    public static function malformedSecret(): self
    {
        return new self('Webhook signing secret is not valid base64 after the "whsec_" prefix.');
    }

    public static function timestampOutOfTolerance(int $skewSeconds, int $toleranceSeconds): self
    {
        return new self(
            "Webhook timestamp is {$skewSeconds}s away from now, outside the {$toleranceSeconds}s tolerance. "
            . 'This is either a replayed delivery or a clock-skew problem on this server.'
        );
    }

    public static function noMatchingSignature(): self
    {
        return new self('No signature in the webhook-signature header matched the expected value.');
    }
}
