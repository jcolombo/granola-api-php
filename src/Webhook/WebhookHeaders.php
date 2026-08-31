<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Webhook;

use Jcolombo\GranolaApiPhp\Exception\SignatureVerificationException;

/**
 * Case-insensitive access to a delivery's HTTP headers, from whatever shape
 * your stack hands you.
 *
 *     WebhookHeaders::fromGlobals()                    // plain PHP / $_SERVER
 *     WebhookHeaders::fromArray(getallheaders())       // Apache
 *     WebhookHeaders::fromArray($request->getHeaders())// PSR-7, Laravel, Symfony
 *     WebhookHeaders::fromAny($whatever)               // any of the above
 *
 * Deliberately not typed against PSR-7 — this package has no framework
 * dependencies, and an array of headers is the one thing every framework can
 * produce.
 */
final class WebhookHeaders
{
    /** Standard Webhooks header carrying the unique delivery id. */
    public const ID = 'webhook-id';

    /** Standard Webhooks header carrying the delivery timestamp, in Unix seconds. */
    public const TIMESTAMP = 'webhook-timestamp';

    /** Standard Webhooks header carrying one or more space-separated signatures. */
    public const SIGNATURE = 'webhook-signature';

    /**
     * @param array<string, string> $headers lowercased name => value
     */
    private function __construct(private readonly array $headers) {}

    /**
     * Normalise a name => value (or name => [values]) map.
     *
     * @param array<string, string|list<string>> $headers
     */
    public static function fromArray(array $headers): self
    {
        $normalised = [];
        foreach ($headers as $name => $value) {
            $normalised[strtolower((string) $name)] = is_array($value)
                ? (string) reset($value)
                : (string) $value;
        }
        return new self($normalised);
    }

    /**
     * Read headers out of $_SERVER, converting HTTP_WEBHOOK_ID back to webhook-id.
     *
     * @param array<string, mixed>|null $server defaults to $_SERVER
     */
    public static function fromGlobals(?array $server = null): self
    {
        $server ??= $_SERVER;
        $headers = [];

        foreach ($server as $key => $value) {
            $key = (string) $key;
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = (string) $value;
        }

        return new self($headers);
    }

    /**
     * Accept an existing instance, a header array, or any object exposing
     * getHeaders() / getHeaderLine() (PSR-7 and friends).
     */
    public static function fromAny(mixed $headers): self
    {
        if ($headers instanceof self) {
            return $headers;
        }
        if (is_array($headers)) {
            return self::fromArray($headers);
        }
        if (is_object($headers) && method_exists($headers, 'getHeaders')) {
            /** @var array<string, string|list<string>> $all */
            $all = $headers->getHeaders();
            return self::fromArray($all);
        }
        if (is_object($headers) && method_exists($headers, 'getHeaderLine')) {
            return self::fromArray([
                self::ID => $headers->getHeaderLine(self::ID),
                self::TIMESTAMP => $headers->getHeaderLine(self::TIMESTAMP),
                self::SIGNATURE => $headers->getHeaderLine(self::SIGNATURE),
            ]);
        }

        throw new \InvalidArgumentException(
            'Cannot read webhook headers from ' . get_debug_type($headers)
            . '. Pass an array, a WebhookHeaders, or a PSR-7 request.'
        );
    }

    public function get(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;
        return $value === null || $value === '' ? null : $value;
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /**
     * @throws SignatureVerificationException when the header is absent or empty
     */
    public function mustGet(string $name): string
    {
        $value = $this->get($name);
        if ($value === null) {
            throw SignatureVerificationException::missingHeader($name);
        }
        return $value;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->headers;
    }
}
