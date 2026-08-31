<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Tests\Unit;

use Jcolombo\GranolaApiPhp\Entity\Resource\WebhookEndpoint;
use Jcolombo\GranolaApiPhp\Enum\WebhookEventType;
use Jcolombo\GranolaApiPhp\Enum\WebhookScope;
use Jcolombo\GranolaApiPhp\Tests\Support\GranolaTestCase;
use Jcolombo\GranolaApiPhp\Tests\Support\MockApi;

final class WebhookEndpointTest extends GranolaTestCase
{
    public function testRegisteringAnEndpointPostsScopesAndReturnsTheSigningSecret(): void
    {
        $api = MockApi::make(MockApi::fixture('webhook-endpoint.create', 201));

        $endpoint = WebhookEndpoint::register(
            'https://example.com/granola-webhooks',
            [WebhookScope::Personal, WebhookScope::Public],
            connection: $api->granola,
        );

        self::assertSame('POST', $api->requestAt(0)?->getMethod());
        self::assertSame('/v1/webhook-endpoints', $api->pathAt(0));
        self::assertSame([
            'url' => 'https://example.com/granola-webhooks',
            'scopes' => ['personal', 'public'],
        ], $api->bodyAt(0));

        self::assertSame('whe_2mKr8fQxLp7Ta3', $endpoint->id());
        self::assertSame('whsec_c2VjcmV0LWtleS1mb3ItZ3Jhbm9sYS10ZXN0cw==', $endpoint->signingSecret());
    }

    public function testEventsAndFolderIdsAreOnlySentWhenGiven(): void
    {
        $api = MockApi::make(MockApi::fixture('webhook-endpoint.create', 201));

        WebhookEndpoint::register(
            'https://example.com/hooks',
            ['personal'],
            [WebhookEventType::NoteGenerated],
            ['fol_4y6LduVdwSKC27'],
            $api->granola,
        );

        self::assertSame([
            'url' => 'https://example.com/hooks',
            'scopes' => ['personal'],
            'events' => ['note.generated'],
            'folder_ids' => ['fol_4y6LduVdwSKC27'],
        ], $api->bodyAt(0));
    }

    public function testTheSigningSecretIsNeverAvailableOnAListedEndpoint(): void
    {
        $api = MockApi::make(MockApi::fixture('webhook-endpoints.list'));

        $endpoint = WebhookEndpoint::all($api->granola)->first();

        self::assertInstanceOf(WebhookEndpoint::class, $endpoint);
        self::assertNull($endpoint->signingSecret());
    }

    public function testSavingPatchesOnlyTheFieldsThatChanged(): void
    {
        $api = MockApi::make(
            MockApi::fixture('webhook-endpoints.list'),
            MockApi::fixture('webhook-endpoint.create'),
        );

        $endpoint = WebhookEndpoint::all($api->granola)->first();
        $endpoint->set('enabled', false);
        $endpoint->save();

        self::assertSame('PATCH', $api->requestAt(1)?->getMethod());
        self::assertSame('/v1/webhook-endpoints/whe_2mKr8fQxLp7Ta3', $api->pathAt(1));
        self::assertSame(['enabled' => false], $api->bodyAt(1));
    }

    public function testSavingWithNothingChangedMakesNoRequest(): void
    {
        $api = MockApi::make(MockApi::fixture('webhook-endpoints.list'));

        WebhookEndpoint::all($api->granola)->first()?->save();

        self::assertSame(1, $api->requestCount());
    }

    public function testReadOnlyFieldsAreNeverIncludedInAPatch(): void
    {
        $api = MockApi::make(
            MockApi::fixture('webhook-endpoints.list'),
            MockApi::fixture('webhook-endpoint.create'),
        );

        $endpoint = WebhookEndpoint::all($api->granola)->first();
        $endpoint->set('id', 'whe_tampered0000');
        $endpoint->set('created_at', '2020-01-01T00:00:00Z');
        $endpoint->set('url', 'https://example.com/moved');
        $endpoint->save();

        self::assertSame(['url' => 'https://example.com/moved'], $api->bodyAt(1));
    }

    public function testDeleteReportsGranolasConfirmation(): void
    {
        $api = MockApi::make(
            MockApi::fixture('webhook-endpoints.list'),
            MockApi::json(['id' => 'whe_2mKr8fQxLp7Ta3', 'object' => 'webhook_endpoint', 'deleted' => true]),
        );

        $deleted = WebhookEndpoint::all($api->granola)->first()?->delete();

        self::assertTrue($deleted);
        self::assertSame('DELETE', $api->requestAt(1)?->getMethod());
    }

    public function testEndpointAccessorsExposeEnumsAndRedaction(): void
    {
        $api = MockApi::make(MockApi::fixture('webhook-endpoints.list'));

        $endpoints = WebhookEndpoint::all($api->granola);
        $mine = $endpoints->find('whe_2mKr8fQxLp7Ta3');
        $theirs = $endpoints->find('whe_3nLs9gRyMq8Ub4');

        self::assertSame([
            WebhookEventType::NoteGenerated,
            WebhookEventType::NoteEdited,
            WebhookEventType::NoteAccessGranted,
        ], $mine?->events());
        self::assertTrue($mine?->isEnabled());
        self::assertFalse($mine?->isUrlRedacted());
        self::assertSame([WebhookScope::Personal, WebhookScope::Public], $mine?->scopes());
        self::assertTrue($mine?->isSubscribedTo(WebhookEventType::NoteEdited));

        self::assertTrue($theirs?->isUrlRedacted());
        self::assertNull($theirs?->createdBy(), 'workspace-managed endpoints have no creator');
        self::assertSame(['fol_4y6LduVdwSKC27'], $theirs?->folderIds());
    }

    public function testCollectionHelpersSplitEnabledFromPaused(): void
    {
        $api = MockApi::make(MockApi::fixture('webhook-endpoints.list'));

        $endpoints = WebhookEndpoint::all($api->granola);

        self::assertCount(1, $endpoints->enabled());
        self::assertCount(1, $endpoints->paused());
        self::assertCount(2, $endpoints->subscribedTo(WebhookEventType::NoteGenerated));
        self::assertCount(1, $endpoints->subscribedTo(WebhookEventType::NoteEdited));
        self::assertSame(
            'whe_2mKr8fQxLp7Ta3',
            $endpoints->findByUrl('https://example.com/granola-webhooks')?->id()
        );
    }

    public function testAnUnpaginatedListingDoesNotClaimThereIsMore(): void
    {
        $api = MockApi::make(MockApi::fixture('webhook-endpoints.list'));

        $endpoints = WebhookEndpoint::all($api->granola);

        self::assertFalse($endpoints->hasMore());
        self::assertNull($endpoints->cursor());
    }
}
