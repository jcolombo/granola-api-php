<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Utility;

class RequestResponse
{
    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $headers
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?array $body,
        public readonly array $headers,
        public readonly int $responseCode,
        public readonly string $responseReason,
        public readonly float $responseTime,
        public readonly RequestAbstraction $request,
        public readonly ?string $fromCacheKey = null,
    ) {}

    /**
     * True when the body contains $key, optionally holding at least $minQty entries.
     */
    public function validBody(string $key, int $minQty = 0): bool
    {
        if ($this->body === null || !array_key_exists($key, $this->body)) {
            return false;
        }
        if ($minQty > 0 && is_array($this->body[$key])) {
            return count($this->body[$key]) >= $minQty;
        }
        return true;
    }

    /**
     * The API's machine-readable error code, when it sent one (e.g. TRANSCRIPT_TOO_LARGE).
     */
    public function errorCode(): ?string
    {
        foreach (['code', 'error_code', 'error'] as $key) {
            if (isset($this->body[$key]) && is_string($this->body[$key])) {
                return $this->body[$key];
            }
        }
        return null;
    }

    /**
     * Best available human-readable failure reason.
     */
    public function errorMessage(): string
    {
        foreach (['message', 'detail', 'error_description'] as $key) {
            if (isset($this->body[$key]) && is_string($this->body[$key])) {
                return $this->body[$key];
            }
        }
        if (isset($this->body['errors']) && is_array($this->body['errors'])) {
            return implode('; ', array_map('strval', $this->body['errors']));
        }
        return "HTTP {$this->responseCode}: {$this->responseReason}";
    }

    /**
     * Case-insensitive single header lookup.
     */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return is_array($values) ? (string) reset($values) : (string) $values;
            }
        }
        return null;
    }
}
