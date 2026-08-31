<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Entity;

use Jcolombo\GranolaApiPhp\Configuration;
use Jcolombo\GranolaApiPhp\Utility\Error;
use Jcolombo\GranolaApiPhp\Utility\ErrorSeverity;

/**
 * Lookup between config keys ("note", "notes") and the classes that implement them.
 *
 * Every mapping lives in `classMap.entity` in configuration, so an application
 * can subclass any resource or collection and register the replacement with
 * overload() without touching this package.
 */
class EntityMap
{
    /**
     * Resource FQCN for a singular key.
     */
    public static function resource(string $key): ?string
    {
        $entry = Configuration::get("classMap.entity.{$key}");
        if (!is_array($entry) || ($entry['type'] ?? null) !== 'resource') {
            return null;
        }
        return $entry['resource'] ?? null;
    }

    /**
     * Collection FQCN for a plural key.
     */
    public static function collection(string $key): ?string
    {
        $entry = Configuration::get("classMap.entity.{$key}");
        if (!is_array($entry) || ($entry['type'] ?? null) !== 'collection') {
            return null;
        }
        $collection = $entry['collection'] ?? null;
        if ($collection === true) {
            return Configuration::get('classMap.defaultCollection');
        }
        return is_string($collection) ? $collection : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function entity(string $key): ?array
    {
        $entry = Configuration::get("classMap.entity.{$key}");
        return is_array($entry) ? $entry : null;
    }

    public static function exists(string $key): bool
    {
        return Configuration::has("classMap.entity.{$key}");
    }

    /**
     * @return list<string>
     */
    public static function mapKeys(): array
    {
        $entities = Configuration::get('classMap.entity', []);
        return is_array($entities) ? array_keys($entities) : [];
    }

    /**
     * Reverse lookup: resource FQCN → singular config key.
     */
    public static function extractKey(string $className): ?string
    {
        $entities = Configuration::get('classMap.entity', []);
        if (!is_array($entities)) {
            return null;
        }

        foreach ($entities as $key => $entry) {
            if (!is_array($entry) || ($entry['type'] ?? null) !== 'resource') {
                continue;
            }
            $mapped = $entry['resource'] ?? null;
            if ($mapped === $className || (is_string($mapped) && is_subclass_of($className, $mapped))) {
                return (string) $key;
            }
        }
        return null;
    }

    /**
     * Point a config key at your own subclass.
     *
     *     EntityMap::overload('note', MyNote::class);
     *     EntityMap::overload('notes', MyNoteCollection::class, 'collection');
     */
    public static function overload(string $key, string $className, string $type = 'resource'): void
    {
        if (Configuration::get('devMode')) {
            $base = $type === 'collection' ? AbstractCollection::class : AbstractResource::class;
            if (!is_subclass_of($className, $base)) {
                Error::handle(
                    ErrorSeverity::WARN,
                    "EntityMap overload: {$className} does not extend " . $base
                );
                return;
            }
        }

        Configuration::set("classMap.entity.{$key}.{$type}", $className);
    }
}
