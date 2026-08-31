<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Entity\Value;

use Jcolombo\GranolaApiPhp\Enum\SpeakerAttribution;
use Jcolombo\GranolaApiPhp\Enum\SpeakerSource;

/**
 * Who spoke a transcript item.
 *
 * Only `source` is guaranteed. macOS transcripts carry `attribution`; iOS
 * transcripts may instead carry an anonymous `diarizationLabel`, and `name`
 * appears only when Granola resolved the speaker to a person.
 */
final class Speaker implements \JsonSerializable, \Stringable
{
    public function __construct(
        public readonly SpeakerSource $source,
        public readonly ?SpeakerAttribution $attribution = null,
        public readonly ?string $diarizationLabel = null,
        public readonly ?string $name = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            source: SpeakerSource::tryFrom((string) ($data['source'] ?? '')) ?? SpeakerSource::Microphone,
            attribution: isset($data['attribution'])
                ? SpeakerAttribution::tryFrom((string) $data['attribution'])
                : null,
            diarizationLabel: isset($data['diarization_label']) ? (string) $data['diarization_label'] : null,
            name: isset($data['name']) ? (string) $data['name'] : null,
        );
    }

    /**
     * True when this line was spoken by the note's owner.
     */
    public function isMe(): bool
    {
        return $this->attribution === SpeakerAttribution::Me;
    }

    /**
     * The best label available: resolved name, then diarization bucket, then
     * attribution, then the audio source.
     */
    public function label(): string
    {
        return $this->name
            ?? $this->diarizationLabel
            ?? $this->attribution?->value
            ?? $this->source->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'source' => $this->source->value,
            'attribution' => $this->attribution?->value,
            'diarization_label' => $this->diarizationLabel,
            'name' => $this->name,
        ], static fn (mixed $v): bool => $v !== null);
    }

    public function __toString(): string
    {
        return $this->label();
    }
}
