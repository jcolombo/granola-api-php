<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Jcolombo\GranolaApiPhp\Cache\Cache;
use Jcolombo\GranolaApiPhp\Cache\ScrubCache;
use Jcolombo\GranolaApiPhp\Exception\ConfigurationException;
use Jcolombo\GranolaApiPhp\Utility\Error;
use Jcolombo\GranolaApiPhp\Utility\ErrorSeverity;
use Jcolombo\GranolaApiPhp\Utility\HttpMethod;
use Jcolombo\GranolaApiPhp\Utility\Log;
use Jcolombo\GranolaApiPhp\Utility\RateLimiter;
use Jcolombo\GranolaApiPhp\Utility\RequestAbstraction;
use Jcolombo\GranolaApiPhp\Utility\RequestResponse;

/**
 * A connection to the Granola API, and the registry of every connection made.
 *
 * One key, one connection. The key in `connection.apiKey` is the default, so the
 * common case needs no arguments at all; extra keys are added alongside it and
 * addressed by name:
 *
 *     $granola   = Granola::connect();                          // default key
 *     $workspace = Granola::connect($wsKey, 'workspace');       // second key
 *     $same      = Granola::connection('workspace');            // fetched later
 *
 * @override OVERRIDE-004
 */
final class Granola
{
    private const USER_AGENT = 'granola-api-php (+https://github.com/jcolombo/granola-api-php)';

    /** @var array<string, self> keyed by API key */
    private static array $connections = [];

    /** @var array<string, string> alias => API key */
    private static array $aliases = [];

    private static ?string $defaultKey = null;

    private string $apiKey;

    private string $connectionUrl;

    private string $connectionName;

    private bool $useLogging;

    private ?ClientInterface $client = null;

    private function __construct() {}

    // ── Connection registry ─────────────────────────────────────────────

    /**
     * Open (or reuse) a connection.
     *
     * @param ?string $apiKey Granola API key. Null uses `connection.apiKey` from configuration.
     * @param ?string $name   Optional alias, so this key can be retrieved later by name.
     * @param ?string $url    Optional base URL override, for proxies and test doubles.
     *
     * @throws ConfigurationException when no key is given and none is configured
     */
    public static function connect(?string $apiKey = null, ?string $name = null, ?string $url = null): self
    {
        $apiKey ??= Configuration::get('connection.apiKey');

        if (!is_string($apiKey) || trim($apiKey) === '') {
            throw ConfigurationException::missingApiKey();
        }
        $apiKey = trim($apiKey);

        $instance = self::$connections[$apiKey] ?? null;

        if ($instance === null) {
            $instance = new self();
            $instance->apiKey = $apiKey;
            $instance->connectionUrl = $url ?? (string) Configuration::get('connection.url');
            $instance->connectionName = $name ?? self::fingerprint($apiKey);
            $instance->useLogging = (bool) Configuration::get('enabled.logging', false);

            self::$connections[$apiKey] = $instance;

            Log::onlyIf((bool) Configuration::get('log.connections', false))
                ->log("Granola connection created: {$instance->connectionName}", ['url' => $instance->connectionUrl]);
        }

        if ($name !== null) {
            self::$aliases[$name] = $apiKey;
            $instance->connectionName = $name;
        }

        self::$defaultKey ??= $apiKey;

        return $instance;
    }

    /**
     * Retrieve a connection by alias, or the default connection.
     *
     * With no arguments and nothing connected yet, this opens the default
     * connection from configuration — so `Granola::connection()` always returns
     * something usable or throws a clear error.
     *
     * @throws ConfigurationException when the alias is unknown, or no key is configured
     */
    public static function connection(?string $name = null): self
    {
        if ($name !== null) {
            $key = self::$aliases[$name] ?? null;
            if ($key === null || !isset(self::$connections[$key])) {
                throw ConfigurationException::unknownConnection($name);
            }
            return self::$connections[$key];
        }

        if (self::$defaultKey !== null && isset(self::$connections[self::$defaultKey])) {
            return self::$connections[self::$defaultKey];
        }

        return self::connect();
    }

    public static function hasConnection(?string $name = null): bool
    {
        if ($name === null) {
            return self::$defaultKey !== null;
        }
        return isset(self::$aliases[$name]);
    }

    /**
     * Promote an existing named connection to be the one `connection()` returns.
     *
     * @throws ConfigurationException when the alias is unknown
     */
    public static function setDefault(string $name): self
    {
        $connection = self::connection($name);
        self::$defaultKey = $connection->apiKey;
        return $connection;
    }

    /**
     * Forget one connection (by alias or key), or all of them.
     */
    public static function disconnect(?string $nameOrKey = null): void
    {
        if ($nameOrKey === null) {
            self::$connections = [];
            self::$aliases = [];
            self::$defaultKey = null;
            return;
        }

        $key = self::$aliases[$nameOrKey] ?? $nameOrKey;

        unset(self::$connections[$key]);
        foreach (self::$aliases as $alias => $aliasKey) {
            if ($aliasKey === $key) {
                unset(self::$aliases[$alias]);
            }
        }
        if (self::$defaultKey === $key) {
            self::$defaultKey = array_key_first(self::$connections) ?: null;
        }
    }

    /**
     * Every open connection, keyed by alias where one was given.
     *
     * @return array<string, self>
     */
    public static function connections(): array
    {
        $out = [];
        foreach (self::$connections as $connection) {
            $out[$connection->connectionName] = $connection;
        }
        return $out;
    }

    // ── Execution ───────────────────────────────────────────────────────

    /**
     * Run one request: cache lookup, rate-limit wait, HTTP call, 429 retry, cache store.
     *
     * Never throws on an HTTP status. A non-2xx comes back as a RequestResponse
     * with `success === false`, and is reported through Error::handleApiError()
     * — which throws ApiException only if `error.throwOnApiError` is enabled.
     *
     * @override OVERRIDE-006
     */
    public function execute(RequestAbstraction $request): RequestResponse
    {
        $startTime = microtime(true);
        $isRead = $request->method === HttpMethod::GET;

        if ($isRead) {
            $cached = Cache::fetch($request->makeCacheKey());
            if ($cached !== null) {
                return $cached;
            }
        }

        RateLimiter::waitIfNeeded($this->apiKey);

        // Auth and error handling are set per request, not only in the client's
        // defaults, so that a client injected through setHttpClient() — a proxy,
        // a retry middleware, a test double — stays authenticated and still
        // returns 4xx/5xx as responses rather than exceptions.
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
                'User-Agent' => self::USER_AGENT,
            ],
            'http_errors' => false,
        ];
        if ($request->data !== null) {
            $options['json'] = $request->data;
        }
        if ($request->query !== []) {
            $options['query'] = $request->query;
        }

        try {
            $httpResponse = $this->getClient()->request(
                $request->method->value,
                $this->absoluteUrl($request->resourceUrl),
                $options
            );
        } catch (GuzzleException $e) {
            $response = new RequestResponse(
                success: false,
                body: null,
                headers: [],
                responseCode: 0,
                responseReason: $e->getMessage(),
                responseTime: microtime(true) - $startTime,
                request: $request,
            );
            Error::handle(ErrorSeverity::FATAL, 'Granola connection failed: ' . $e->getMessage());
            return $response;
        }

        $statusCode = $httpResponse->getStatusCode();
        $headers = $httpResponse->getHeaders();
        $rawBody = (string) $httpResponse->getBody();

        RateLimiter::recordRequest($this->apiKey);
        RateLimiter::updateFromHeaders($this->apiKey, $headers);

        if ($statusCode === 429 && RateLimiter::shouldRetry($this->apiKey)) {
            RateLimiter::waitForRetry($this->apiKey);
            return $this->execute($request);
        }
        RateLimiter::resetRetry($this->apiKey);

        $response = new RequestResponse(
            success: $statusCode >= 200 && $statusCode < 300,
            body: self::decodeBody($rawBody),
            headers: $headers,
            responseCode: $statusCode,
            responseReason: $httpResponse->getReasonPhrase(),
            responseTime: microtime(true) - $startTime,
            request: $request,
        );

        Log::onlyIf($this->useLogging && (bool) Configuration::get('log.requests', true))
            ->log("{$request->method->value} /{$request->resourceUrl}", [
                'status' => $statusCode,
                'time' => round($response->responseTime, 3),
            ]);

        if (!$response->success) {
            // 413 on Get Note is an expected, recoverable answer (the transcript
            // is simply too big to inline), so Note handles it rather than
            // reporting it as a failure here.
            if ($statusCode !== 413) {
                Error::handleApiError($response);
            }
            return $response;
        }

        if ($isRead) {
            Cache::store($request->makeCacheKey(), $response);
        } else {
            ScrubCache::invalidate($request->resourceUrl);
        }

        return $response;
    }

    // ── Accessors ───────────────────────────────────────────────────────

    public function getName(): string
    {
        return $this->connectionName;
    }

    public function getUrl(): string
    {
        return $this->connectionUrl;
    }

    /**
     * A safe, loggable identifier for this key. Never returns the key itself.
     */
    public function getFingerprint(): string
    {
        return self::fingerprint($this->apiKey);
    }

    /**
     * Swap in a pre-built HTTP client. Used by the test suite to serve fixtures,
     * and by applications that need custom middleware, proxies or retries.
     */
    public function setHttpClient(?ClientInterface $client): self
    {
        $this->client = $client;
        return $this;
    }

    private function getClient(): ClientInterface
    {
        if ($this->client === null) {
            $this->client = new Client([
                'timeout' => (float) Configuration::get('connection.timeout', 30),
                'verify' => (bool) Configuration::get('connection.verify', true),
                'http_errors' => false,
            ]);
        }
        return $this->client;
    }

    /**
     * Resolve a relative resource path against this connection's base URL.
     *
     * Requests carry an absolute URL rather than relying on the client's
     * base_uri, so an injected client needs no particular configuration.
     */
    private function absoluteUrl(string $resourceUrl): string
    {
        return rtrim($this->connectionUrl, '/') . '/' . ltrim($resourceUrl, '/');
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeBody(string $rawBody): ?array
    {
        if (trim($rawBody) === '') {
            return null;
        }
        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Error::handle(ErrorSeverity::WARN, 'Granola response was not valid JSON: ' . $e->getMessage());
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    private static function fingerprint(string $apiKey): string
    {
        return 'GranolaApi-***' . substr($apiKey, -4);
    }
}
