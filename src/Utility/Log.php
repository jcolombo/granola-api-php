<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Utility;

use Jcolombo\GranolaApiPhp\Configuration;

/**
 * Minimal append-only file logger.
 *
 * Deliberately dependency-free. Applications that already have PSR-3 can route
 * everything through their own logger with Log::registerWriter().
 */
class Log
{
    private static ?self $instance = null;

    private bool $shouldLog = true;

    /** @var ?callable(string, array<string, mixed>): void */
    private static $writer = null;

    private function __construct(
        private readonly bool $enabled,
        private readonly ?string $logPath,
    ) {}

    /**
     * Fluent guard: Log::onlyIf($cond)->log(...) writes only when $cond is true.
     */
    public static function onlyIf(bool $condition): self
    {
        $instance = self::getInstance();
        $instance->shouldLog = $condition;
        return $instance;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $message, array $context = []): void
    {
        $write = $this->shouldLog;
        $this->shouldLog = true;

        if (!$write) {
            return;
        }

        if (self::$writer !== null) {
            (self::$writer)($message, $context);
            return;
        }

        if (!$this->enabled || $this->logPath === null) {
            return;
        }

        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        if ($context !== []) {
            $line .= ' ' . json_encode($context);
        }

        $file = $this->resolveLogFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND);
    }

    /**
     * Hand every log line to the application instead of writing a file.
     * Pass null to restore file logging.
     *
     * @param ?callable(string, array<string, mixed>): void $writer
     */
    public static function registerWriter(?callable $writer): void
    {
        self::$writer = $writer;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self(
                (bool) Configuration::get('enabled.logging', false),
                Configuration::get('path.logs')
            );
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    private function resolveLogFile(): string
    {
        $path = (string) $this->logPath;
        if (is_dir($path)) {
            return rtrim($path, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . (string) Configuration::get('error.logFilename', 'granola-errors.log');
        }
        return $path;
    }
}
