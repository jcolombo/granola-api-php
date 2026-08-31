<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Tests\Unit;

use Jcolombo\GranolaApiPhp\Request;
use Jcolombo\GranolaApiPhp\Tests\Support\GranolaTestCase;
use Jcolombo\GranolaApiPhp\Tests\Support\MockApi;
use Jcolombo\GranolaApiPhp\Utility\Converter;
use Jcolombo\GranolaApiPhp\Utility\HttpMethod;
use Jcolombo\GranolaApiPhp\Utility\RequestAbstraction;

final class RequestTest extends GranolaTestCase
{
    public function testPathJoinsSegmentsWithoutEscapingSeparators(): void
    {
        self::assertSame(
            'v1/notes/not_1d3tmYTlCICgjy/transcript',
            Request::path('v1/notes', 'not_1d3tmYTlCICgjy', 'transcript')
        );
    }

    public function testPathEncodesSegmentContentAndDropsEmptyParts(): void
    {
        self::assertSame('v1/notes/a%20b', Request::path('/v1/notes/', '', 'a b'));
        self::assertSame('v1/notes/not%3Fx%26y', Request::path('v1/notes', 'not?x&y'));
    }

    public function testPathRefusesTraversalSegments(): void
    {
        // IDs reach this from webhook payloads and user input, so '..' must not
        // be able to redirect a request at another endpoint.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/path segment '\.\.'/");

        Request::path('v1/notes', '../admin');
    }

    public function testAnInjectedHttpClientIsStillAuthenticated(): void
    {
        // setHttpClient() replaces the client wholesale; auth must not be lost
        // with it, or every proxy and middleware setup silently 401s.
        $api = MockApi::make(MockApi::json([]));

        Request::get($api->granola, 'v1/notes');

        self::assertSame('Bearer grn_test_key_0001', $api->requestAt(0)?->getHeaderLine('Authorization'));
        self::assertSame(
            'https://public-api.granola.ai/v1/notes',
            (string) $api->requestAt(0)?->getUri()
        );
    }

    public function testEmptyQueryValuesAreDroppedRatherThanSentBlank(): void
    {
        $api = MockApi::make(MockApi::json([]));

        Request::get($api->granola, 'v1/notes', [
            'cursor' => null,
            'folder_id' => '',
            'tags' => [],
            'page_size' => 30,
        ]);

        self::assertSame(['page_size' => '30'], $api->queryAt(0));
    }

    public function testConverterRendersQueryValuesPredictably(): void
    {
        self::assertSame('true', Converter::convertForQuery(true));
        self::assertSame('false', Converter::convertForQuery(false));
        self::assertSame('30', Converter::convertForQuery(30));
        self::assertSame('a,b', Converter::convertForQuery(['a', 'b']));
        self::assertSame(
            '2026-08-31T00:00:00+00:00',
            Converter::convertForQuery(new \DateTimeImmutable('2026-08-31T00:00:00+00:00'))
        );
    }

    public function testTheCacheKeyIgnoresQueryParameterOrder(): void
    {
        $a = new RequestAbstraction(HttpMethod::GET, 'v1/notes', null, ['page_size' => '10', 'cursor' => 'x']);
        $b = new RequestAbstraction(HttpMethod::GET, 'v1/notes', null, ['cursor' => 'x', 'page_size' => '10']);
        $c = new RequestAbstraction(HttpMethod::GET, 'v1/folders', null, ['cursor' => 'x', 'page_size' => '10']);

        self::assertSame($a->makeCacheKey(), $b->makeCacheKey());
        self::assertNotSame($a->makeCacheKey(), $c->makeCacheKey());
        self::assertStringStartsWith('grnapi-', $a->makeCacheKey());
    }

    public function testAConnectionFailureComesBackAsAFailedResponseNotAnUncaughtError(): void
    {
        $api = MockApi::make(MockApi::status(500, [], '{"message":"upstream exploded"}'));

        $response = Request::get($api->granola, 'v1/notes');

        self::assertFalse($response->success);
        self::assertSame(500, $response->responseCode);
        self::assertSame('upstream exploded', $response->errorMessage());
    }
}
