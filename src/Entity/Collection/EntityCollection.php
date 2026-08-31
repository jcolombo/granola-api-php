<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Entity\Collection;

use Jcolombo\GranolaApiPhp\Entity\AbstractCollection;

/**
 * Generic fallback collection.
 *
 * Every built-in resource has a purpose-built collection; this exists so that a
 * resource registered by an application through EntityMap::overload() still has
 * something workable without writing a collection class:
 *
 *     (new EntityCollection(MyThing::class))->resultsUnder('things')->fetchAll();
 */
class EntityCollection extends AbstractCollection
{
    private ?string $runtimeResultKey = null;

    /**
     * Name the response key holding the array of objects.
     */
    public function resultsUnder(string $key): static
    {
        $this->runtimeResultKey = $key;
        return $this;
    }

    protected function resultKey(): string
    {
        return $this->runtimeResultKey ?? static::RESULT_KEY;
    }
}
