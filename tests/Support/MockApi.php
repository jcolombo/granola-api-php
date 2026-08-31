<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Jcolombo\GranolaApiPhp\Granola;
use Psr\Http\Message\RequestInterface;

/**
 * A Granola connection wired to canned responses, so the unit suite exercises
 * the real request/parse path without a network or an API key.
 */
final class MockApi
{
    /** @var list<array{request: RequestInterface, response: mixed}> */
    public array $history = [];

    private function __construct(
        public readonly Granola $granola,
        private readonly MockHandler $handler,
    ) {}

    /**
     * @param Response ...$responses served in order, one per request
     */
    public static function make(Response ...$responses): self
    {
        Granola::disconnect();

        $handler = new MockHandler($responses);
        $stack = HandlerStack::create($handler);

        $granola = Granola::connect('grn_test_key_0001');
        $self = new self($granola, $handler);

        $stack->push(Middleware::history($self->history));

        $granola->setHttpClient(new Client([
            'handler' => $stack,
            'base_uri' => 'https://public-api.granola.ai/',
            'http_errors' => false,
        ]));

        return $self;
    }

    /**
     * A 200 response carrying a fixture file's contents.
     */
    public static function fixture(string $name, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], Fixtures::raw($name));
    }

    /**
     * A response carrying an inline array as JSON.
     *
     * @param array<string, mixed> $body
     */
    public static function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    /**
     * @param array<string, string> $headers
     */
    public static function status(int $status, array $headers = [], string $body = ''): Response
    {
        return new Response($status, $headers, $body);
    }

    public function requestCount(): int
    {
        return count($this->history);
    }

    public function requestAt(int $index): ?RequestInterface
    {
        return $this->history[$index]['request'] ?? null;
    }

    public function lastRequest(): ?RequestInterface
    {
        return $this->history === [] ? null : $this->history[count($this->history) - 1]['request'];
    }

    /**
     * Decoded query string of the nth request.
     *
     * @return array<string, string>
     */
    public function queryAt(int $index): array
    {
        $request = $this->requestAt($index);
        if ($request === null) {
            return [];
        }
        parse_str($request->getUri()->getQuery(), $query);
        /** @var array<string, string> $query */
        return $query;
    }

    public function pathAt(int $index): string
    {
        return $this->requestAt($index)?->getUri()->getPath() ?? '';
    }

    /**
     * Decoded JSON body of the nth request.
     *
     * @return array<string, mixed>
     */
    public function bodyAt(int $index): array
    {
        $request = $this->requestAt($index);
        if ($request === null) {
            return [];
        }
        $decoded = json_decode((string) $request->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
