<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Utility;

use Jcolombo\GranolaApiPhp\Configuration;
use Jcolombo\GranolaApiPhp\Exception\ApiException;

class Error
{
    /**
     * @param array<string, mixed> $context
     */
    public static function handle(ErrorSeverity|string $severity, string $message, array $context = []): void
    {
        if (!Configuration::get('error.enabled', true)) {
            return;
        }

        if (is_string($severity)) {
            $severity = ErrorSeverity::from($severity);
        }

        $handlers = Configuration::get("error.handlers.{$severity->value}", []);
        if (!is_array($handlers)) {
            $handlers = [];
        }

        foreach ($handlers as $handler) {
            match ($handler) {
                'log' => Log::getInstance()->log("[{$severity->value}] {$message}", $context),
                'echo' => self::echoError($severity, $message, $context),
                default => null,
            };
        }

        if (Configuration::get('error.triggerPhpErrors', false)) {
            $level = match ($severity) {
                ErrorSeverity::NOTICE => E_USER_NOTICE,
                ErrorSeverity::WARN => E_USER_WARNING,
                ErrorSeverity::FATAL => E_USER_WARNING,
            };
            trigger_error($message, $level);
        }
    }

    /**
     * Report a non-2xx API response.
     *
     * By default this logs and returns, matching the sibling SDKs — the caller
     * inspects `$collection->lastResponse()` or `$resource->lastResponse()` to
     * see what went wrong. Set `error.throwOnApiError` to true to get an
     * ApiException instead, which most applications will want.
     *
     * @throws ApiException
     */
    public static function handleApiError(RequestResponse $response): void
    {
        $message = $response->errorMessage();

        self::handle(ErrorSeverity::FATAL, $message, [
            'status' => $response->responseCode,
            'url' => $response->request->resourceUrl,
            'code' => $response->errorCode(),
        ]);

        if (Configuration::get('error.throwOnApiError', false)) {
            throw ApiException::fromResponse($response);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function echoError(ErrorSeverity $severity, string $message, array $context): void
    {
        $output = '[' . strtoupper($severity->value) . "] {$message}";
        if ($context !== []) {
            $output .= ' ' . json_encode($context);
        }
        echo $output . PHP_EOL;
    }
}
