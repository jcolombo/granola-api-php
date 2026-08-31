<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Entity\Resource;

use Jcolombo\GranolaApiPhp\Entity\AbstractResource;
use Jcolombo\GranolaApiPhp\Entity\Value\Speaker;

/**
 * One utterance in a meeting transcript.
 *
 * Transcript items carry no ID of their own — they are ordered, not addressed —
 * so a TranscriptCollection is indexed positionally rather than by id.
 */
class TranscriptItem extends AbstractResource
{
    public const LABEL = 'Transcript item';

    /** Nested under a note; TranscriptCollection supplies the full path. */
    public const API_PATH = '';

    public const PROP_TYPES = [
        'speaker' => 'value:' . Speaker::class,
        'text' => 'text',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    public function speaker(): ?Speaker
    {
        $speaker = $this->get('speaker');
        return $speaker instanceof Speaker ? $speaker : null;
    }

    public function text(): string
    {
        return (string) $this->get('text', '');
    }

    public function startTime(): ?\DateTimeImmutable
    {
        $value = $this->get('start_time');
        return $value instanceof \DateTimeImmutable ? $value : null;
    }

    public function endTime(): ?\DateTimeImmutable
    {
        $value = $this->get('end_time');
        return $value instanceof \DateTimeImmutable ? $value : null;
    }

    /**
     * How long this utterance lasted, in seconds.
     */
    public function durationSeconds(): ?int
    {
        $start = $this->startTime();
        $end = $this->endTime();
        if ($start === null || $end === null) {
            return null;
        }
        return $end->getTimestamp() - $start->getTimestamp();
    }

    /**
     * True when the note's owner spoke this line.
     */
    public function isMe(): bool
    {
        return $this->speaker()?->isMe() ?? false;
    }

    /**
     * "Alice Smith: we should ship on Friday" — the form most transcript
     * processing wants.
     */
    public function toLine(string $separator = ': '): string
    {
        $label = $this->speaker()?->label();
        return $label === null ? $this->text() : $label . $separator . $this->text();
    }

    public function __toString(): string
    {
        return $this->toLine();
    }
}
