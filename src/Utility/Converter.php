<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Utility;

use Jcolombo\GranolaApiPhp\Configuration;

/**
 * Coerces raw JSON values into PHP types according to a resource's PROP_TYPES map.
 *
 * Supported type strings:
 *   text | uri | email | id | date        → string
 *   integer                               → int
 *   boolean                               → bool
 *   datetime                              → DateTimeImmutable
 *   array                                 → list<mixed> (values untouched)
 *   json                                  → array<string, mixed> (values untouched)
 *   enum:a|b|c                            → string, validated in devMode
 *   value:<FQCN>                          → FQCN::fromArray()
 *   valueList:<FQCN>                      → list<FQCN>
 */
class Converter
{
    public static function getPrimitiveType(string $propType): string
    {
        return match (true) {
            in_array($propType, ['text', 'uri', 'email', 'id', 'date'], true) => 'string',
            $propType === 'integer' => 'int',
            $propType === 'boolean' => 'bool',
            $propType === 'datetime' => 'DateTimeImmutable',
            $propType === 'array', $propType === 'json' => 'array',
            str_starts_with($propType, 'enum:') => 'string',
            str_starts_with($propType, 'value:') => substr($propType, 6),
            str_starts_with($propType, 'valueList:') => 'array',
            default => 'mixed',
        };
    }

    public static function convertToPhpValue(mixed $value, string $propType): mixed
    {
        if ($value === null) {
            return null;
        }

        return match (true) {
            in_array($propType, ['text', 'uri', 'email', 'id', 'date'], true) => (string) $value,
            $propType === 'integer' => (int) $value,
            $propType === 'boolean' => (bool) $value,
            $propType === 'datetime' => self::toDateTime($value),
            $propType === 'array', $propType === 'json' => (array) $value,
            str_starts_with($propType, 'enum:') => self::validateEnum((string) $value, $propType),
            str_starts_with($propType, 'value:') => self::toValueObject($value, substr($propType, 6)),
            str_starts_with($propType, 'valueList:') => self::toValueObjectList($value, substr($propType, 10)),
            default => $value,
        };
    }

    /**
     * Convert a PHP value into its JSON request representation.
     */
    public static function convertForRequest(mixed $value, string $propType): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if ($propType === 'boolean') {
            return (bool) $value;
        }
        if (is_array($value)) {
            return array_map(
                static fn (mixed $item): mixed => $item instanceof \JsonSerializable ? $item->jsonSerialize() : $item,
                $value
            );
        }
        return $value;
    }

    /**
     * Convert a PHP value into its query-string representation.
     */
    public static function convertForQuery(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_array($value)) {
            return implode(',', array_map([self::class, 'convertForQuery'], $value));
        }
        return (string) $value;
    }

    private static function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Exception $e) {
            Error::handle(ErrorSeverity::WARN, "Unparseable datetime '{$value}': " . $e->getMessage());
            return null;
        }
    }

    private static function toValueObject(mixed $value, string $class): mixed
    {
        if (!is_array($value)) {
            return null;
        }
        /** @var class-string $class */
        return $class::fromArray($value);
    }

    /**
     * @return list<mixed>
     */
    private static function toValueObjectList(mixed $value, string $class): array
    {
        if (!is_array($value)) {
            return [];
        }
        /** @var class-string $class */
        return array_values(array_map(
            static fn (mixed $item): mixed => is_array($item) ? $class::fromArray($item) : null,
            $value
        ));
    }

    private static function validateEnum(string $value, string $propType): string
    {
        if (Configuration::get('devMode')) {
            $options = explode('|', substr($propType, 5));
            if (!in_array($value, $options, true)) {
                Error::handle(ErrorSeverity::WARN, "Value '{$value}' not in enum options: {$propType}");
            }
        }
        return $value;
    }
}
