<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Utility;

use Jcolombo\GranolaApiPhp\Configuration;

/**
 * Client-side limiter matching Granola's published budget: a 25-request burst
 * over any 5-second window, and 5 requests/second sustained (300/minute).
 *
 * Granola does not document rate-limit response headers, so the windows are
 * tracked locally from request timestamps. A 429 is still handled defensively:
 * `Retry-After` is honoured when present, otherwise the retry backs off
 * exponentially from `rateLimit.retryDelayMs`.
 *
 * @override OVERRIDE-003
 */
class RateLimiter
{
    /** @var array<string, array{timestamps: float[], retryCount: int, retryAfter: ?float}> */
    private static array $state = [];

    /**
     * Block until another request fits inside both windows.
     */
    public static function waitIfNeeded(string $connectionKey): void
    {
        if (!Configuration::get('rateLimit.enabled', true)) {
            return;
        }

        self::initState($connectionKey);

        $burstLimit = (int) Configuration::get('rateLimit.burstLimit', 25);
        $burstWindow = (float) Configuration::get('rateLimit.burstWindowSeconds', 5);
        $perMinute = (int) Configuration::get('rateLimit.perMinute', 300);
        $buffer = (int) Configuration::get('rateLimit.safetyBuffer', 1);

        // A server-supplied Retry-After outranks every local calculation.
        $retryAfter = self::$state[$connectionKey]['retryAfter'];
        if ($retryAfter !== null && $retryAfter > microtime(true)) {
            self::sleepUntil($retryAfter);
        }
        self::$state[$connectionKey]['retryAfter'] = null;

        self::prune($connectionKey, 60.0);

        // Burst window (default 25 per 5s)
        self::waitForWindow($connectionKey, $burstWindow, max(1, $burstLimit - $buffer));

        // Sustained window (default 300 per 60s)
        self::waitForWindow($connectionKey, 60.0, max(1, $perMinute - $buffer));

        // Optional floor between calls, off by default.
        $minDelay = (float) Configuration::get('rateLimit.minDelayMs', 0) / 1000;
        $timestamps = self::$state[$connectionKey]['timestamps'];
        if ($minDelay > 0 && $timestamps !== []) {
            $sinceLast = microtime(true) - max($timestamps);
            if ($sinceLast < $minDelay) {
                usleep((int) (($minDelay - $sinceLast) * 1_000_000));
            }
        }
    }

    public static function recordRequest(string $connectionKey): void
    {
        self::initState($connectionKey);
        self::$state[$connectionKey]['timestamps'][] = microtime(true);
    }

    public static function shouldRetry(string $connectionKey): bool
    {
        self::initState($connectionKey);
        return self::$state[$connectionKey]['retryCount'] < (int) Configuration::get('rateLimit.maxRetries', 3);
    }

    public static function waitForRetry(string $connectionKey): void
    {
        self::initState($connectionKey);

        $retryAfter = self::$state[$connectionKey]['retryAfter'];
        if ($retryAfter !== null && $retryAfter > microtime(true)) {
            self::sleepUntil($retryAfter);
            self::$state[$connectionKey]['retryAfter'] = null;
        } else {
            $delayMs = (int) Configuration::get('rateLimit.retryDelayMs', 1000);
            $attempt = self::$state[$connectionKey]['retryCount'];
            usleep($delayMs * (2 ** $attempt) * 1000);
        }

        self::$state[$connectionKey]['retryCount']++;
    }

    public static function resetRetry(string $connectionKey): void
    {
        self::initState($connectionKey);
        self::$state[$connectionKey]['retryCount'] = 0;
    }

    /**
     * Record a server-supplied Retry-After (seconds or HTTP-date) for the next wait.
     *
     * @param array<string, mixed> $headers
     */
    public static function updateFromHeaders(string $connectionKey, array $headers): void
    {
        self::initState($connectionKey);

        foreach ($headers as $name => $values) {
            if (strcasecmp((string) $name, 'retry-after') !== 0) {
                continue;
            }

            $value = is_array($values) ? (string) reset($values) : (string) $values;
            if ($value === '') {
                return;
            }

            if (is_numeric($value)) {
                self::$state[$connectionKey]['retryAfter'] = microtime(true) + (float) $value;
                return;
            }

            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                self::$state[$connectionKey]['retryAfter'] = (float) $timestamp;
            }
            return;
        }
    }

    public static function reset(?string $connectionKey = null): void
    {
        if ($connectionKey === null) {
            self::$state = [];
            return;
        }
        unset(self::$state[$connectionKey]);
    }

    /**
     * Requests recorded inside the trailing $seconds. Exposed for diagnostics and tests.
     */
    public static function usage(string $connectionKey, float $seconds = 60.0): int
    {
        self::initState($connectionKey);
        $cutoff = microtime(true) - $seconds;
        return count(array_filter(
            self::$state[$connectionKey]['timestamps'],
            static fn (float $ts): bool => $ts > $cutoff
        ));
    }

    private static function waitForWindow(string $connectionKey, float $window, int $limit): void
    {
        $now = microtime(true);
        $inWindow = array_filter(
            self::$state[$connectionKey]['timestamps'],
            static fn (float $ts): bool => ($now - $ts) < $window
        );

        if (count($inWindow) < $limit) {
            return;
        }

        self::sleepUntil(min($inWindow) + $window);
    }

    private static function prune(string $connectionKey, float $keepSeconds): void
    {
        $cutoff = microtime(true) - $keepSeconds;
        self::$state[$connectionKey]['timestamps'] = array_values(array_filter(
            self::$state[$connectionKey]['timestamps'],
            static fn (float $ts): bool => $ts > $cutoff
        ));
    }

    private static function sleepUntil(float $target): void
    {
        $delta = $target - microtime(true);
        if ($delta > 0) {
            usleep((int) ($delta * 1_000_000));
        }
    }

    private static function initState(string $connectionKey): void
    {
        if (!isset(self::$state[$connectionKey])) {
            self::$state[$connectionKey] = [
                'timestamps' => [],
                'retryCount' => 0,
                'retryAfter' => null,
            ];
        }
    }
}
