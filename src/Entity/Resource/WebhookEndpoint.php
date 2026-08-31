<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Entity\Resource;

use Jcolombo\GranolaApiPhp\Entity\AbstractResource;
use Jcolombo\GranolaApiPhp\Entity\Collection\WebhookEndpointCollection;
use Jcolombo\GranolaApiPhp\Entity\Value\User;
use Jcolombo\GranolaApiPhp\Enum\WebhookEventType;
use Jcolombo\GranolaApiPhp\Enum\WebhookScope;
use Jcolombo\GranolaApiPhp\Granola;
use Jcolombo\GranolaApiPhp\Request;

/**
 * A registered HTTPS URL that Granola delivers note events to.
 *
 * The only writable resource in Granola's API.
 *
 *     $endpoint = WebhookEndpoint::register(
 *         'https://example.com/granola-webhooks',
 *         [WebhookScope::Personal, WebhookScope::Public],
 *     );
 *     $secret = $endpoint->signingSecret();   // shown once, right here — store it
 *
 * Later changes are partial: set what you want and call save(), which PATCHes
 * only the fields that actually changed.
 *
 *     $endpoint->enabled = false;
 *     $endpoint->save();
 */
class WebhookEndpoint extends AbstractResource
{
    public const LABEL = 'Webhook endpoint';
    public const API_PATH = 'v1/webhook-endpoints';
    public const OBJECT_TYPE = 'webhook_endpoint';
    public const ID_PREFIX = 'whe_';

    public const PROP_TYPES = [
        'id' => 'id',
        'object' => 'text',
        'url' => 'uri',
        'url_redacted' => 'boolean',
        'events' => 'array',
        'folder_ids' => 'array',
        'scopes' => 'array',
        'created_by' => 'value:' . User::class,
        'enabled' => 'boolean',
        'created_at' => 'datetime',
    ];

    public const MUTABLE = ['url', 'scopes', 'events', 'folder_ids', 'enabled'];

    /** Returned by Create only, never again. */
    protected ?string $signingSecret = null;

    // ── Reading ─────────────────────────────────────────────────────────

    /**
     * Start a listing of webhook endpoints.
     */
    public static function list(null|string|Granola $connection = null): WebhookEndpointCollection
    {
        /** @var WebhookEndpointCollection */
        return parent::list($connection);
    }

    /**
     * Every endpoint this key can see. Not paginated by Granola.
     */
    public static function all(null|string|Granola $connection = null): WebhookEndpointCollection
    {
        return static::list($connection)->fetch();
    }

    // ── Writing ─────────────────────────────────────────────────────────

    /**
     * Register a new endpoint and return it, carrying its signing secret.
     *
     * @param list<WebhookScope|string>     $scopes    Required. A workspace key must pass exactly [Workspace].
     * @param list<WebhookEventType|string> $events    Defaults to every event.
     * @param list<string>                  $folderIds Restrict delivery to these folders and their children.
     */
    public static function register(
        string $url,
        array $scopes,
        array $events = [],
        array $folderIds = [],
        null|string|Granola $connection = null
    ): static {
        $endpoint = static::new($connection);

        $body = [
            'url' => $url,
            'scopes' => self::normaliseScopes($scopes),
        ];
        if ($events !== []) {
            $body['events'] = self::normaliseEvents($events);
        }
        if ($folderIds !== []) {
            $body['folder_ids'] = array_values($folderIds);
        }

        $response = Request::post($endpoint->connection(), static::API_PATH, $body);
        $endpoint->lastResponse = $response;

        if ($response->success && $response->body !== null) {
            $endpoint->hydrate($response->body);
            $secret = $response->body['signing_secret'] ?? null;
            $endpoint->signingSecret = is_string($secret) ? $secret : null;
        }

        return $endpoint;
    }

    /**
     * PATCH only the fields changed since this endpoint was loaded.
     *
     * A no-op when nothing changed.
     */
    public function save(): static
    {
        $changes = $this->writableChanges();
        $id = $this->id();

        if ($changes === [] || $id === null) {
            return $this;
        }

        $response = Request::patch($this->connection(), Request::path(static::API_PATH, $id), $changes);
        $this->lastResponse = $response;

        if ($response->success && $response->body !== null) {
            $this->hydrate($response->body);
        }

        return $this;
    }

    /**
     * Delete this endpoint. Deliveries stop immediately.
     */
    public function delete(): bool
    {
        $id = $this->id();
        if ($id === null) {
            return false;
        }

        $response = Request::delete($this->connection(), Request::path(static::API_PATH, $id));
        $this->lastResponse = $response;

        return $response->success && (bool) ($response->body['deleted'] ?? false);
    }

    /**
     * Pause deliveries. Configuration and signing secret survive; events that
     * occur while paused are not delivered later.
     */
    public function disable(): static
    {
        $this->set('enabled', false);
        return $this->save();
    }

    /**
     * Resume deliveries.
     */
    public function enable(): static
    {
        $this->set('enabled', true);
        return $this->save();
    }

    /**
     * Replace the folder filter. An empty array removes it.
     *
     * @param list<string> $folderIds
     */
    public function restrictToFolders(array $folderIds): static
    {
        $this->set('folder_ids', array_values($folderIds));
        return $this;
    }

    /**
     * Replace the event subscription.
     *
     * @param list<WebhookEventType|string> $events
     */
    public function subscribeTo(array $events): static
    {
        $this->set('events', self::normaliseEvents($events));
        return $this;
    }

    // ── Accessors ───────────────────────────────────────────────────────

    /**
     * The signing secret, available only on the instance returned by register().
     *
     * Granola shows it exactly once. Store it before this object goes out of
     * scope — there is no endpoint that will tell you again.
     */
    public function signingSecret(): ?string
    {
        return $this->signingSecret;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->get('enabled', false);
    }

    /**
     * True when `url` has been reduced to its origin because this key did not
     * create the endpoint (the path can carry credentials).
     */
    public function isUrlRedacted(): bool
    {
        return (bool) $this->get('url_redacted', false);
    }

    /**
     * Subscribed events as enum cases. Unrecognised names are skipped; use
     * `get('events')` for the raw strings.
     *
     * @return list<WebhookEventType>
     */
    public function events(): array
    {
        $out = [];
        foreach ((array) $this->get('events', []) as $event) {
            $case = WebhookEventType::tryFrom((string) $event);
            if ($case !== null) {
                $out[] = $case;
            }
        }
        return $out;
    }

    /**
     * @return list<WebhookScope>
     */
    public function scopes(): array
    {
        $out = [];
        foreach ((array) $this->get('scopes', []) as $scope) {
            $case = WebhookScope::tryFrom((string) $scope);
            if ($case !== null) {
                $out[] = $case;
            }
        }
        return $out;
    }

    /**
     * Folder IDs deliveries are restricted to. Empty means unrestricted.
     *
     * @return list<string>
     */
    public function folderIds(): array
    {
        return array_values(array_map('strval', (array) $this->get('folder_ids', [])));
    }

    public function isSubscribedTo(WebhookEventType|string $event): bool
    {
        $value = $event instanceof WebhookEventType ? $event->value : $event;
        return in_array($value, array_map('strval', (array) $this->get('events', [])), true);
    }

    /**
     * The user who created this endpoint. Null for workspace-managed endpoints
     * and for creators whose account was deleted.
     */
    public function createdBy(): ?User
    {
        $user = $this->get('created_by');
        return $user instanceof User ? $user : null;
    }

    // ── Internals ───────────────────────────────────────────────────────

    /**
     * @param list<WebhookScope|string> $scopes
     * @return list<string>
     */
    private static function normaliseScopes(array $scopes): array
    {
        return array_values(array_map(
            static fn (WebhookScope|string $s): string => $s instanceof WebhookScope ? $s->value : $s,
            $scopes
        ));
    }

    /**
     * @param list<WebhookEventType|string> $events
     * @return list<string>
     */
    private static function normaliseEvents(array $events): array
    {
        return array_values(array_map(
            static fn (WebhookEventType|string $e): string => $e instanceof WebhookEventType ? $e->value : $e,
            $events
        ));
    }
}
