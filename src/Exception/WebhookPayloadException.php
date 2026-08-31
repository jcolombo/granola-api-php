<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Exception;

/**
 * The webhook body verified correctly but is not a payload this SDK understands.
 *
 * Unknown event *types* do not raise this — they parse into a WebhookEvent with
 * a null `type` and the original string in `rawType`, so a new Granola event
 * never breaks a running receiver.
 */
class WebhookPayloadException extends GranolaException
{
    public static function invalidJson(string $reason): self
    {
        return new self("Webhook body is not valid JSON: {$reason}");
    }

    public static function missingField(string $field): self
    {
        return new self("Webhook payload is missing the required '{$field}' field.");
    }

    public static function noConnection(): self
    {
        return new self(
            'This WebhookEvent has no Granola connection, so it cannot fetch the note. '
            . 'Pass a connection to Webhook::parse(), or call $event->withConnection($granola).'
        );
    }
}
