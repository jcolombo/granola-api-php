<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Entity\Collection;

use Jcolombo\GranolaApiPhp\Entity\AbstractCollection;
use Jcolombo\GranolaApiPhp\Entity\Resource\AuditEvent;

/**
 * A cursor-paginated list of workspace audit events.
 *
 *     AuditEvent::list()
 *         ->action('workspace')
 *         ->occurredAfter('2026-08-01')
 *         ->each();
 *
 * Both date filters must fall inside Granola's one-year retention window; an
 * earlier date is rejected with a 400.
 *
 * The response key is `events`, not `audit_events`.
 */
class AuditEventCollection extends AbstractCollection
{
    public const RESULT_KEY = 'events';

    protected const PAGE_SIZE_CONFIG = 'auditPageSize';

    /**
     * Match an exact action, or every action in a namespace.
     *
     * `workspace` returns `workspace.member_added` but not
     * `workspace_automation.created` — the prefix match stops at the dot.
     */
    public function action(string $actionOrPrefix): static
    {
        return $this->filter('action', $actionOrPrefix);
    }

    public function occurredBefore(string|\DateTimeInterface $when): static
    {
        return $this->filter('occurred_before', $when);
    }

    public function occurredAfter(string|\DateTimeInterface $when): static
    {
        return $this->filter('occurred_after', $when);
    }

    public function occurredBetween(string|\DateTimeInterface $from, string|\DateTimeInterface $to): static
    {
        return $this->occurredAfter($from)->occurredBefore($to);
    }

    // ── Typed accessors ─────────────────────────────────────────────────

    /**
     * @return list<AuditEvent>
     */
    public function all(): array
    {
        /** @var list<AuditEvent> */
        return parent::all();
    }

    public function first(): ?AuditEvent
    {
        $event = parent::first();
        return $event instanceof AuditEvent ? $event : null;
    }

    public function last(): ?AuditEvent
    {
        $event = parent::last();
        return $event instanceof AuditEvent ? $event : null;
    }

    public function find(string $id): ?AuditEvent
    {
        $event = parent::find($id);
        return $event instanceof AuditEvent ? $event : null;
    }

    public function current(): AuditEvent
    {
        /** @var AuditEvent */
        return parent::current();
    }

    public function offsetGet(mixed $offset): ?AuditEvent
    {
        $event = parent::offsetGet($offset);
        return $event instanceof AuditEvent ? $event : null;
    }

    /**
     * @return \Generator<int, AuditEvent>
     */
    public function each(): \Generator
    {
        yield from parent::each();
    }
}
