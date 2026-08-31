<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Entity\Value;

/**
 * The calendar event a note was taken against.
 *
 * Null on notes captured outside a meeting, and every field inside it is itself
 * nullable — an event may exist with no title, organiser or times.
 */
final class CalendarEvent implements \JsonSerializable
{
    /**
     * @param list<CalendarInvitee> $invitees
     */
    public function __construct(
        public readonly ?string $eventTitle,
        public readonly array $invitees,
        public readonly ?string $organiser,
        public readonly ?string $calendarEventId,
        public readonly ?\DateTimeImmutable $scheduledStartTime,
        public readonly ?\DateTimeImmutable $scheduledEndTime,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $invitees = [];
        foreach ((array) ($data['invitees'] ?? []) as $invitee) {
            if (is_array($invitee)) {
                $invitees[] = CalendarInvitee::fromArray($invitee);
            }
        }

        return new self(
            eventTitle: isset($data['event_title']) ? (string) $data['event_title'] : null,
            invitees: $invitees,
            organiser: isset($data['organiser']) ? (string) $data['organiser'] : null,
            calendarEventId: isset($data['calendar_event_id']) ? (string) $data['calendar_event_id'] : null,
            scheduledStartTime: self::toDate($data['scheduled_start_time'] ?? null),
            scheduledEndTime: self::toDate($data['scheduled_end_time'] ?? null),
        );
    }

    /**
     * Scheduled length in whole minutes, when both times are known.
     */
    public function scheduledMinutes(): ?int
    {
        if ($this->scheduledStartTime === null || $this->scheduledEndTime === null) {
            return null;
        }
        return intdiv(
            $this->scheduledEndTime->getTimestamp() - $this->scheduledStartTime->getTimestamp(),
            60
        );
    }

    /**
     * Every invitee email address.
     *
     * @return list<string>
     */
    public function inviteeEmails(): array
    {
        return array_map(static fn (CalendarInvitee $i): string => $i->email, $this->invitees);
    }

    /**
     * True when at least one invitee sits outside the organiser's email domain.
     */
    public function isExternal(): bool
    {
        if ($this->organiser === null) {
            return false;
        }
        $at = strrpos($this->organiser, '@');
        if ($at === false) {
            return false;
        }
        $host = strtolower(substr($this->organiser, $at + 1));

        foreach ($this->invitees as $invitee) {
            if ($invitee->domain() !== null && $invitee->domain() !== $host) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'event_title' => $this->eventTitle,
            'invitees' => $this->invitees,
            'organiser' => $this->organiser,
            'calendar_event_id' => $this->calendarEventId,
            'scheduled_start_time' => $this->scheduledStartTime?->format(DATE_ATOM),
            'scheduled_end_time' => $this->scheduledEndTime?->format(DATE_ATOM),
        ];
    }

    private static function toDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
