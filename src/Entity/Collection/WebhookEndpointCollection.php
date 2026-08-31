<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Entity\Collection;

use Jcolombo\GranolaApiPhp\Entity\AbstractCollection;
use Jcolombo\GranolaApiPhp\Entity\Resource\WebhookEndpoint;
use Jcolombo\GranolaApiPhp\Enum\WebhookEventType;

/**
 * Every webhook endpoint the key can see.
 *
 * Unlike the other listings this one is not paginated — Granola returns the
 * complete set, with no `hasMore` or `cursor` — so fetch() is always enough and
 * fetchAll() simply does the same thing.
 */
class WebhookEndpointCollection extends AbstractCollection
{
    public const RESULT_KEY = 'webhook_endpoints';

    /**
     * Endpoints currently delivering.
     *
     * @return list<WebhookEndpoint>
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn ($e): bool => $e instanceof WebhookEndpoint && $e->isEnabled()
        ));
    }

    /**
     * Endpoints that are registered but paused.
     *
     * @return list<WebhookEndpoint>
     */
    public function paused(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn ($e): bool => $e instanceof WebhookEndpoint && !$e->isEnabled()
        ));
    }

    /**
     * Endpoints subscribed to one event.
     *
     * @return list<WebhookEndpoint>
     */
    public function subscribedTo(WebhookEventType|string $event): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn ($e): bool => $e instanceof WebhookEndpoint && $e->isSubscribedTo($event)
        ));
    }

    /**
     * Find an endpoint by its delivery URL.
     *
     * Only matches endpoints this key created — anyone else's `url` comes back
     * reduced to its origin.
     */
    public function findByUrl(string $url): ?WebhookEndpoint
    {
        foreach ($this->all() as $endpoint) {
            if ($endpoint instanceof WebhookEndpoint && (string) $endpoint->get('url') === $url) {
                return $endpoint;
            }
        }
        return null;
    }

    // ── Typed accessors ─────────────────────────────────────────────────

    /**
     * @return list<WebhookEndpoint>
     */
    public function all(): array
    {
        /** @var list<WebhookEndpoint> */
        return parent::all();
    }

    public function first(): ?WebhookEndpoint
    {
        $endpoint = parent::first();
        return $endpoint instanceof WebhookEndpoint ? $endpoint : null;
    }

    public function last(): ?WebhookEndpoint
    {
        $endpoint = parent::last();
        return $endpoint instanceof WebhookEndpoint ? $endpoint : null;
    }

    public function find(string $id): ?WebhookEndpoint
    {
        $endpoint = parent::find($id);
        return $endpoint instanceof WebhookEndpoint ? $endpoint : null;
    }

    public function current(): WebhookEndpoint
    {
        /** @var WebhookEndpoint */
        return parent::current();
    }

    public function offsetGet(mixed $offset): ?WebhookEndpoint
    {
        $endpoint = parent::offsetGet($offset);
        return $endpoint instanceof WebhookEndpoint ? $endpoint : null;
    }

    /**
     * @return \Generator<int, WebhookEndpoint>
     */
    public function each(): \Generator
    {
        yield from parent::each();
    }
}
