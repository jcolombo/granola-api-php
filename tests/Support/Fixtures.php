<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Tests\Support;

/**
 * Loader for the canned API payloads in tests/Fixtures.
 *
 * Every fixture is copied from the shapes in Granola's published OpenAPI
 * document, so a change on their side shows up here as a failing test rather
 * than as a surprise in production.
 */
final class Fixtures
{
    public static function path(string $name): string
    {
        return dirname(__DIR__) . '/Fixtures/' . $name . '.json';
    }

    public static function raw(string $name): string
    {
        $path = self::path($name);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Missing fixture: {$path}");
        }
        return $contents;
    }

    /**
     * @return array<string, mixed>
     */
    public static function array(string $name): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(self::raw($name), true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }
}
