<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Entity\Value;

/**
 * Someone invited to the calendar event behind a note.
 *
 * Invitees carry only an email — an invitee is not necessarily an attendee, and
 * Granola does not resolve them to names.
 */
final class CalendarInvitee implements \JsonSerializable, \Stringable
{
    public function __construct(
        public readonly string $email,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(email: (string) ($data['email'] ?? ''));
    }

    public function domain(): ?string
    {
        $at = strrpos($this->email, '@');
        return $at === false ? null : strtolower(substr($this->email, $at + 1));
    }

    /**
     * @return array{email: string}
     */
    public function jsonSerialize(): array
    {
        return ['email' => $this->email];
    }

    public function __toString(): string
    {
        return $this->email;
    }
}
