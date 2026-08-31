<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp;

use Adbar\Dot;

/**
 * Dot-notation configuration singleton.
 *
 * Defaults ship in default.granolaapi.config.json at the package root. An
 * application layers its own values on top with load() or overload(); each call
 * deep-merges, so an override file only needs the keys it actually changes.
 *
 *     Configuration::overload(__DIR__ . '/config');        // finds granolaapi.config.json
 *     Configuration::set('connection.apiKey', $key);       // or set values directly
 *
 * @override OVERRIDE-001
 */
class Configuration
{
    private static ?self $instance = null;

    private Dot $data;

    /** @var list<string> */
    private array $paths;

    private function __construct(array $defaults, string $path)
    {
        $this->data = new Dot($defaults);
        $this->paths = [$path];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::getInstance()->data->get($key, $default);
    }

    public static function has(string $key): bool
    {
        return self::getInstance()->data->has($key);
    }

    public static function set(string $key, mixed $value): void
    {
        self::getInstance()->data->set($key, $value);
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return self::getInstance()->data->all();
    }

    /**
     * Merge a JSON config file over the current values.
     *
     * @throws \JsonException when the file is not valid JSON
     */
    public static function load(string $path): void
    {
        $instance = self::getInstance();

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Unable to read Granola config file: {$path}");
        }

        $override = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $instance->data = new Dot(array_replace_recursive($instance->data->all(), (array) $override));
        $instance->paths[] = $path;
    }

    /**
     * Merge an override if it exists, silently doing nothing if it does not.
     *
     * Accepts either a file path or a directory holding granolaapi.config.json.
     */
    public static function overload(string $path): void
    {
        if (is_dir($path)) {
            $path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'granolaapi.config.json';
        }
        if (!file_exists($path)) {
            return;
        }
        self::load($path);
    }

    /**
     * Every config file merged so far, defaults first.
     *
     * @return list<string>
     */
    public static function loadedPaths(): array
    {
        return self::getInstance()->paths;
    }

    /**
     * Drop all loaded configuration and fall back to packaged defaults.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    private static function getInstance(): self
    {
        if (self::$instance === null) {
            $configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'default.granolaapi.config.json';
            $contents = file_get_contents($configPath);
            if ($contents === false) {
                throw new \RuntimeException("Unable to read packaged defaults: {$configPath}");
            }
            $defaults = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            self::$instance = new self((array) $defaults, $configPath);
        }
        return self::$instance;
    }
}
