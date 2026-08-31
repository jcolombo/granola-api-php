<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Entity;

use Jcolombo\GranolaApiPhp\Configuration;
use Jcolombo\GranolaApiPhp\Granola;
use Jcolombo\GranolaApiPhp\Utility\RequestResponse;

/**
 * Shared connection handling for resources and collections.
 *
 * A connection may be passed explicitly, named by its alias, or left out
 * entirely — in which case the default connection is resolved on first use.
 */
abstract class AbstractEntity
{
    protected ?Granola $connection = null;

    protected ?string $connectionName = null;

    protected ?RequestResponse $lastResponse = null;

    public function __construct(null|string|Granola $connection = null)
    {
        if ($connection instanceof Granola) {
            $this->connection = $connection;
        } elseif (is_string($connection)) {
            $this->connectionName = $connection;
        }
    }

    public static function new(null|string|Granola $connection = null): static
    {
        return new static($connection);
    }

    /**
     * Start a collection for this resource type.
     */
    public static function list(null|string|Granola $connection = null): AbstractCollection
    {
        $resourceKey = EntityMap::extractKey(static::class);
        if ($resourceKey === null) {
            throw new \RuntimeException('No classMap entry registered for ' . static::class);
        }

        $entity = EntityMap::entity($resourceKey);
        $collectionKey = $entity['collectionKey'] ?? null;
        if ($collectionKey === null) {
            throw new \RuntimeException("classMap entry '{$resourceKey}' has no collectionKey");
        }

        $collectionClass = EntityMap::collection($collectionKey)
            ?? Configuration::get('classMap.defaultCollection');

        return new $collectionClass(static::class, $connection);
    }

    /**
     * The connection this entity uses, resolving the alias or default on first call.
     */
    public function connection(): Granola
    {
        if ($this->connection === null) {
            $this->connection = Granola::connection($this->connectionName);
        }
        return $this->connection;
    }

    public function getConnection(): ?Granola
    {
        return $this->connection;
    }

    public function setConnection(Granola $connection): static
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * The raw response behind the most recent call, including failures.
     *
     * With `error.throwOnApiError` off (the default) this is how you find out
     * why a fetch came back empty.
     */
    public function lastResponse(): ?RequestResponse
    {
        return $this->lastResponse;
    }

    /**
     * True when the most recent call succeeded. Null before anything was called.
     */
    public function succeeded(): ?bool
    {
        return $this->lastResponse?->success;
    }
}
