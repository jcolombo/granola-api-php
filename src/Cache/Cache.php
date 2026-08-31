<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Cache;

use Jcolombo\GranolaApiPhp\Configuration;
use Jcolombo\GranolaApiPhp\Utility\RequestResponse;

/**
 * Optional file-backed response cache for GET requests.
 *
 * Off unless `enabled.cache` is true AND a writable directory is known, from
 * either the GRNAPI_REQUEST_CACHE_PATH constant or the `path.cache` config key.
 * Applications with their own cache (PSR-16, APCu, Redis) should call
 * registerCacheMethods() instead and ignore the filesystem entirely.
 *
 * @override OVERRIDE-005
 */
class Cache
{
    private static ?self $instance = null;

    private readonly bool $enabled;

    private readonly ?string $cachePath;

    /** @var ?callable(string): ?RequestResponse */
    private $customRead = null;

    /** @var ?callable(string, RequestResponse): void */
    private $customWrite = null;

    /** @var ?callable(?string): void */
    private $customClear = null;

    private function __construct()
    {
        $base = self::resolveBasePath();
        $this->cachePath = $base === null
            ? null
            : rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'granola-cache' . DIRECTORY_SEPARATOR;
        $this->enabled = Configuration::get('enabled.cache') === true && $this->cachePath !== null;
    }

    public static function fetch(string $key): ?RequestResponse
    {
        $self = self::getInstance();

        if ($self->customRead !== null) {
            return ($self->customRead)($key);
        }
        if (!$self->enabled) {
            return null;
        }

        $file = $self->cachePath . $key;
        if (!is_file($file)) {
            return null;
        }

        // Age from mtime rather than a stored expiry timestamp, so a DST shift
        // cannot make an entry look valid for an extra hour.
        $lifespan = (int) Configuration::get('cache.lifespan', 300);
        if (time() - (int) filemtime($file) >= $lifespan) {
            @unlink($file);
            return null;
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $response = @unserialize($contents, ['allowed_classes' => true]);
        return $response instanceof RequestResponse ? $response : null;
    }

    public static function store(string $key, RequestResponse $response): void
    {
        $self = self::getInstance();

        if ($self->customWrite !== null) {
            ($self->customWrite)($key, $response);
            return;
        }
        if (!$self->enabled) {
            return;
        }

        if (!is_dir($self->cachePath)) {
            @mkdir($self->cachePath, 0755, true);
        }

        @file_put_contents($self->cachePath . $key, serialize($response));
    }

    public static function clear(?string $key = null): void
    {
        $self = self::getInstance();

        if ($self->customClear !== null) {
            ($self->customClear)($key);
            return;
        }
        if (!$self->enabled || $self->cachePath === null) {
            return;
        }

        if ($key !== null) {
            $file = $self->cachePath . $key;
            if (is_file($file)) {
                @unlink($file);
            }
            return;
        }

        $files = glob($self->cachePath . 'grnapi-*');
        foreach ($files ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Replace the filesystem backend with the application's own cache.
     *
     * @param callable(string): ?RequestResponse       $read
     * @param callable(string, RequestResponse): void  $write
     * @param callable(?string): void                  $clear
     */
    public static function registerCacheMethods(callable $read, callable $write, callable $clear): void
    {
        $self = self::getInstance();
        $self->customRead = $read;
        $self->customWrite = $write;
        $self->customClear = $clear;
    }

    public static function isEnabled(): bool
    {
        $self = self::getInstance();
        return $self->enabled || $self->customRead !== null;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    private static function resolveBasePath(): ?string
    {
        if (defined('GRNAPI_REQUEST_CACHE_PATH')) {
            return (string) constant('GRNAPI_REQUEST_CACHE_PATH');
        }
        $configured = Configuration::get('path.cache');
        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    private static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}
