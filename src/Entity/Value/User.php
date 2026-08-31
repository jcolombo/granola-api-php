<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Entity\Value;

/**
 * A person Granola knows by email — a note's owner, a meeting attendee, or the
 * creator of a webhook endpoint.
 */
final class User implements \JsonSerializable, \Stringable
{
    public function __construct(
        public readonly ?string $name,
        public readonly string $email,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: isset($data['name']) ? (string) $data['name'] : null,
            email: (string) ($data['email'] ?? ''),
        );
    }

    /**
     * The name when Granola has one, otherwise the email.
     */
    public function displayName(): string
    {
        return $this->name !== null && $this->name !== '' ? $this->name : $this->email;
    }

    /**
     * The email's domain, lowercased — handy for telling colleagues from guests.
     */
    public function domain(): ?string
    {
        $at = strrpos($this->email, '@');
        return $at === false ? null : strtolower(substr($this->email, $at + 1));
    }

    /**
     * @return array{name: ?string, email: string}
     */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'email' => $this->email];
    }

    public function __toString(): string
    {
        return $this->displayName();
    }
}
