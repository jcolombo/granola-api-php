<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Exception;

/**
 * Granola answered Get Note with 413 / TRANSCRIPT_TOO_LARGE: the transcript
 * cannot be returned inline and must be paged from
 * GET /v1/notes/{note_id}/transcript.
 *
 * With `notes.autoFallbackLargeTranscript` enabled (the default) the SDK makes
 * that second call for you and this is never thrown.
 */
class TranscriptTooLargeException extends GranolaException
{
    public function __construct(public readonly string $noteId)
    {
        parent::__construct(
            "Transcript for note {$noteId} is too large to return inline. "
            . "Page it from Note::transcript(), or leave notes.autoFallbackLargeTranscript enabled.",
            413
        );
    }
}
