<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Enum;

/**
 * Which audio stream a transcript item came from.
 *
 * iOS currently captures a single stream and reports every item as `microphone`;
 * do not treat that as permanent.
 */
enum SpeakerSource: string
{
    case Microphone = 'microphone';
    case Speaker = 'speaker';
}
