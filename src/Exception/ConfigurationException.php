<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Exception;

/**
 * Thrown when the SDK is asked to do something it has not been configured for —
 * most often connecting without an API key.
 */
class ConfigurationException extends GranolaException
{
    public static function missingApiKey(): self
    {
        return new self(
            'No Granola API key available. Pass one to Granola::connect($apiKey), '
            . 'or set "connection.apiKey" in your granolaapi.config.json override.'
        );
    }

    public static function unknownConnection(string $name): self
    {
        return new self("No Granola connection named '{$name}' has been created.");
    }

    public static function missingSigningSecret(): self
    {
        return new self(
            'No webhook signing secret available. Pass one to Webhook::parse(), '
            . 'or set "webhook.signingSecret" in your granolaapi.config.json override. '
            . 'The secret is returned exactly once, by Create Webhook Endpoint.'
        );
    }
}
