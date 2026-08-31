<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Exception;

use Jcolombo\GranolaApiPhp\Utility\RequestResponse;

/**
 * A non-2xx response from the Granola API.
 *
 * Only thrown when `error.throwOnApiError` is enabled; otherwise failures are
 * logged and surfaced through `lastResponse()` on the resource or collection.
 */
class ApiException extends GranolaException
{
    private function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?string $errorCode,
        public readonly ?RequestResponse $response,
    ) {
        parent::__construct($message, $statusCode);
    }

    public static function fromResponse(RequestResponse $response): self
    {
        $code = $response->errorCode();
        $suffix = $code !== null ? " [{$code}]" : '';

        return new self(
            "Granola API error {$response->responseCode}{$suffix}: " . $response->errorMessage(),
            $response->responseCode,
            $code,
            $response,
        );
    }

    /**
     * True when Granola rejected the API key.
     */
    public function isUnauthorized(): bool
    {
        return $this->statusCode === 401;
    }

    /**
     * True when a requested scope is disabled by the workspace's API access controls.
     */
    public function isForbidden(): bool
    {
        return $this->statusCode === 403;
    }

    public function isNotFound(): bool
    {
        return $this->statusCode === 404;
    }

    public function isRateLimited(): bool
    {
        return $this->statusCode === 429;
    }
}
