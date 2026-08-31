<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Entity\Resource;

use Jcolombo\GranolaApiPhp\Entity\AbstractResource;
use Jcolombo\GranolaApiPhp\Entity\Collection\AuditEventCollection;
use Jcolombo\GranolaApiPhp\Granola;

/**
 * One entry from the workspace audit log.
 *
 *     foreach (AuditEvent::list()->action('workspace')->each() as $event) {
 *         echo $event->action(), ' by ', $event->actorLabel(), "\n";
 *     }
 *
 * `action` is an open set of dot-separated strings that Granola adds to over
 * time, and `data` carries whatever that action records — in camelCase, because
 * those are Granola's internal field names. Neither is modelled as an enum or a
 * typed object here, because doing so would break the first time Granola ships
 * a new action.
 *
 * Events are ordered by `collected_at`, not `occurred_at`: Granola learns about
 * some events after the fact, so `collected_at` is the field that never moves
 * under a cursor.
 *
 * Requires a workspace API key on an Enterprise plan; other keys get 401/403.
 */
class AuditEvent extends AbstractResource
{
    public const LABEL = 'Audit event';
    public const API_PATH = 'v1/audit';
    public const OBJECT_TYPE = 'audit_event';
    public const ID_PREFIX = 'aud_';

    public const PROP_TYPES = [
        'id' => 'id',
        'object' => 'text',
        'action' => 'text',
        'occurred_at' => 'datetime',
        'collected_at' => 'datetime',
        'actor' => 'json',
        'data' => 'json',
        'context' => 'json',
    ];

    /**
     * Start a filtered, cursor-paginated listing of audit events.
     */
    public static function list(null|string|Granola $connection = null): AuditEventCollection
    {
        /** @var AuditEventCollection */
        return parent::list($connection);
    }

    public function action(): string
    {
        return (string) $this->get('action', '');
    }

    /**
     * Match an action or an action namespace.
     *
     *     $event->isAction('workspace')                // workspace.member_added ✓
     *     $event->isAction('workspace.member_added')    // exact ✓
     *
     * Prefix matching stops at the dot, so 'workspace' never matches
     * 'workspace_automation.created'.
     */
    public function isAction(string $actionOrPrefix): bool
    {
        $action = $this->action();
        return $action === $actionOrPrefix || str_starts_with($action, $actionOrPrefix . '.');
    }

    public function occurredAt(): ?\DateTimeImmutable
    {
        $value = $this->get('occurred_at');
        return $value instanceof \DateTimeImmutable ? $value : null;
    }

    /**
     * When Granola recorded the event — the field its cursor orders by.
     */
    public function collectedAt(): ?\DateTimeImmutable
    {
        $value = $this->get('collected_at');
        return $value instanceof \DateTimeImmutable ? $value : null;
    }

    // ── Actor ───────────────────────────────────────────────────────────

    /**
     * One of: user, api_key, system, anonymous. Empty when absent.
     */
    public function actorType(): string
    {
        return (string) ($this->actor()['object'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function actor(): array
    {
        $actor = $this->get('actor');
        return is_array($actor) ? $actor : [];
    }

    /**
     * The acting user's email, when a resolvable person performed the action.
     */
    public function actorEmail(): ?string
    {
        $email = $this->actor()['email'] ?? null;
        return is_string($email) ? $email : null;
    }

    /**
     * The acting user's Granola ID (`usr_…`), when there is one.
     */
    public function actorUserId(): ?string
    {
        $id = $this->actor()['id'] ?? null;
        return is_string($id) ? $id : null;
    }

    /**
     * The last eight characters of the API key identifier, for api_key actors.
     * Not secret — it is an identifier, not the key.
     */
    public function actorApiKeySuffix(): ?string
    {
        $suffix = $this->actor()['id_suffix'] ?? null;
        return is_string($suffix) ? $suffix : null;
    }

    /**
     * Something printable for any actor variant.
     */
    public function actorLabel(): string
    {
        return match ($this->actorType()) {
            'user' => $this->actorEmail() ?? $this->actorUserId() ?? 'deleted user',
            'api_key' => 'API key …' . ($this->actorApiKeySuffix() ?? '????'),
            'system' => 'system',
            'anonymous' => 'anonymous',
            default => 'unknown',
        };
    }

    // ── Payload ─────────────────────────────────────────────────────────

    /**
     * Action-specific details. Field names are camelCase.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $data = $this->get('data');
        return is_array($data) ? $data : [];
    }

    /**
     * How the request reached Granola: ip_address, user_agent, client_version.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $context = $this->get('context');
        return is_array($context) ? $context : [];
    }

    public function ipAddress(): ?string
    {
        $ip = $this->context()['ip_address'] ?? null;
        return is_string($ip) ? $ip : null;
    }
}
