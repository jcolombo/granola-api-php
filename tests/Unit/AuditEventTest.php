<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Tests\Unit;

use Jcolombo\GranolaApiPhp\Entity\Resource\AuditEvent;
use Jcolombo\GranolaApiPhp\Tests\Support\GranolaTestCase;
use Jcolombo\GranolaApiPhp\Tests\Support\MockApi;

final class AuditEventTest extends GranolaTestCase
{
    public function testAuditEventsReadFromTheEventsKey(): void
    {
        $api = MockApi::make(MockApi::fixture('audit.list'));

        $events = AuditEvent::list($api->granola)->fetch();

        self::assertCount(3, $events);
        self::assertSame('/v1/audit', $api->pathAt(0));
        self::assertSame('workspace.member_added', $events->first()?->action());
    }

    public function testActionPrefixMatchingStopsAtTheDot(): void
    {
        $api = MockApi::make(MockApi::fixture('audit.list'));
        $events = AuditEvent::list($api->granola)->fetch()->all();

        $memberAdded = $events[0];
        $automation = $events[1];

        self::assertTrue($memberAdded->isAction('workspace'));
        self::assertTrue($memberAdded->isAction('workspace.member_added'));
        self::assertFalse($automation->isAction('workspace'), 'workspace_automation is a different namespace');
        self::assertTrue($automation->isAction('workspace_automation'));
    }

    public function testEveryActorVariantGetsAUsableLabel(): void
    {
        $api = MockApi::make(MockApi::fixture('audit.list'));
        $events = AuditEvent::list($api->granola)->fetch()->all();

        self::assertSame('user', $events[0]->actorType());
        self::assertSame('oat@granola.ai', $events[0]->actorEmail());
        self::assertSame('usr_3nQ8vLpZ2kR7dY', $events[0]->actorUserId());
        self::assertSame('oat@granola.ai', $events[0]->actorLabel());

        self::assertSame('api_key', $events[1]->actorType());
        self::assertSame('aB3dE7hK', $events[1]->actorApiKeySuffix());
        self::assertSame('API key …aB3dE7hK', $events[1]->actorLabel());

        self::assertSame('system', $events[2]->actorType());
        self::assertSame('system', $events[2]->actorLabel());
    }

    public function testTimestampsAndContextAreParsed(): void
    {
        $api = MockApi::make(MockApi::fixture('audit.list'));
        $event = AuditEvent::list($api->granola)->fetch()->first();

        self::assertInstanceOf(\DateTimeImmutable::class, $event?->occurredAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $event?->collectedAt());
        self::assertSame('203.0.113.42', $event?->ipAddress());
        self::assertSame(['role' => 'member'], $event?->data());
    }

    public function testDateAndActionFiltersReachTheQueryString(): void
    {
        $api = MockApi::make(MockApi::fixture('audit.list'));

        AuditEvent::list($api->granola)
            ->action('workspace')
            ->occurredBetween('2026-08-01', '2026-08-31')
            ->fetch();

        $query = $api->queryAt(0);
        self::assertSame('workspace', $query['action']);
        self::assertSame('2026-08-01', $query['occurred_after']);
        self::assertSame('2026-08-31', $query['occurred_before']);
    }
}
