<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Enum;

/**
 * Who spoke a transcript item, relative to the note's owner.
 *
 * Absent when attribution is unknown — for example iOS items that only carry an
 * anonymous `diarization_label`.
 */
enum SpeakerAttribution: string
{
    /** The note-taker (the note's owner). */
    case Me = 'me';

    /** Any other meeting participant. */
    case Them = 'them';
}
