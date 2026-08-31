<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Entity;

use Jcolombo\GranolaApiPhp\Utility\Converter;

/**
 * Base for every Granola object.
 *
 * Granola's API is read-only apart from webhook endpoints, so there is no
 * create/update/delete here — WebhookEndpoint, the single writable resource,
 * defines those itself. What every resource does share is hydration, typed
 * property access, and the dirty tracking that lets a PATCH send only what
 * actually changed.
 *
 * @override OVERRIDE-007
 */
abstract class AbstractResource extends AbstractEntity implements \JsonSerializable
{
    /** Human-readable name, used in error messages. */
    public const LABEL = '';

    /** URL path for this resource, e.g. 'v1/notes'. */
    public const API_PATH = '';

    /** The value Granola puts in the object's `object` field, e.g. 'note'. */
    public const OBJECT_TYPE = '';

    /** ID prefix Granola assigns, e.g. 'not_'. */
    public const ID_PREFIX = '';

    /** Property name => Converter type string. */
    public const PROP_TYPES = [];

    /** Properties the API accepts on write. Empty for read-only resources. */
    public const MUTABLE = [];

    /** @var array<string, mixed> */
    protected array $props = [];

    /** @var array<string, mixed> snapshot taken at hydration, for dirty tracking */
    protected array $loaded = [];

    /** @var array<string, mixed> fields returned by the API that PROP_TYPES does not describe */
    protected array $unmapped = [];

    // ── Property access ─────────────────────────────────────────────────

    public function get(string $property, mixed $default = null): mixed
    {
        return $this->props[$property] ?? $this->unmapped[$property] ?? $default;
    }

    public function set(string $property, mixed $value): static
    {
        $this->props[$property] = $this->wash($property, $value);
        return $this;
    }

    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value);
    }

    public function __isset(string $name): bool
    {
        return isset($this->props[$name]) || isset($this->unmapped[$name]);
    }

    public function id(): ?string
    {
        $id = $this->get('id');
        return $id === null ? null : (string) $id;
    }

    /**
     * True once this object holds data from the API.
     */
    public function isLoaded(): bool
    {
        return $this->loaded !== [];
    }

    // ── Hydration ───────────────────────────────────────────────────────

    /**
     * Populate from a decoded API object and take a clean snapshot.
     *
     * @param array<string, mixed> $data
     */
    public function hydrate(array $data): static
    {
        $propTypes = static::PROP_TYPES;

        foreach ($data as $key => $value) {
            if (isset($propTypes[$key])) {
                $this->props[$key] = Converter::convertToPhpValue($value, $propTypes[$key]);
            } else {
                $this->unmapped[$key] = $value;
            }
        }

        $this->loaded = $this->props;

        return $this;
    }

    /**
     * Build a hydrated instance in one call.
     *
     * @param array<string, mixed> $data
     */
    public static function make(array $data, null|string|\Jcolombo\GranolaApiPhp\Granola $connection = null): static
    {
        return static::new($connection)->hydrate($data);
    }

    /**
     * Hydrate from a nested object inside another response.
     *
     * Also the entry point Converter uses for `value:` and `valueList:` types,
     * which is why the signature matches the value objects'.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return static::new()->hydrate($data);
    }

    /**
     * Fields Granola returned that this SDK version does not model.
     *
     * Anything new the API starts sending lands here rather than being dropped,
     * so a payload change never silently loses data.
     *
     * @return array<string, mixed>
     */
    public function unmapped(): array
    {
        return $this->unmapped;
    }

    // ── Dirty tracking ──────────────────────────────────────────────────

    public function isDirty(?string $property = null): bool
    {
        if ($property !== null) {
            return ($this->props[$property] ?? null) !== ($this->loaded[$property] ?? null);
        }
        return $this->getDirty() !== [];
    }

    /**
     * @return list<string>
     */
    public function getDirty(): array
    {
        $dirty = [];
        foreach ($this->props as $key => $value) {
            if (!array_key_exists($key, $this->loaded) || $value !== $this->loaded[$key]) {
                $dirty[] = $key;
            }
        }
        return $dirty;
    }

    /**
     * Discard local edits and return to the last hydrated state.
     */
    public function revert(): static
    {
        $this->props = $this->loaded;
        return $this;
    }

    public function clear(): static
    {
        $this->props = [];
        $this->loaded = [];
        $this->unmapped = [];
        return $this;
    }

    // ── Serialisation ───────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeUnmapped = false): array
    {
        $out = [];
        foreach ($this->props as $key => $value) {
            $out[$key] = self::normalise($value);
        }
        return $includeUnmapped ? array_merge($this->unmapped, $out) : $out;
    }

    public function toJson(int $flags = 0): string
    {
        return (string) json_encode($this->toArray(), $flags);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // ── Internals ───────────────────────────────────────────────────────

    protected function wash(string $property, mixed $value): mixed
    {
        $propTypes = static::PROP_TYPES;
        if (!isset($propTypes[$property])) {
            return $value;
        }
        return Converter::convertToPhpValue($value, $propTypes[$property]);
    }

    /**
     * Request body containing only changed, writable fields.
     *
     * @return array<string, mixed>
     */
    protected function writableChanges(): array
    {
        $mutable = static::MUTABLE;
        $data = [];

        foreach ($this->getDirty() as $key) {
            if (!in_array($key, $mutable, true)) {
                continue;
            }
            $propType = static::PROP_TYPES[$key] ?? null;
            $data[$key] = $propType !== null
                ? Converter::convertForRequest($this->props[$key], $propType)
                : $this->props[$key];
        }

        return $data;
    }

    private static function normalise(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if ($value instanceof \JsonSerializable) {
            return $value->jsonSerialize();
        }
        if (is_array($value)) {
            return array_map([self::class, 'normalise'], $value);
        }
        return $value;
    }
}
