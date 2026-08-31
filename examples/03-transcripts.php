<?php

declare(strict_types=1);

/**
 * 03 — Transcripts.
 *
 * Asking for the transcript with the note is one request instead of two. For a
 * long meeting Granola answers 413 instead, and the SDK falls back to the paged
 * endpoint on its own — so the same code works either way.
 */

use Jcolombo\GranolaApiPhp\Entity\Resource\Note;

require __DIR__ . '/bootstrap.php';

$first = Note::list()->pageSize(1)->fetch()->first();

if ($first === null) {
    echo "No notes visible to this key.\n";
    exit(0);
}

$note = Note::find($first->id(), withTranscript: true);

echo 'Note: ', (string) $note->title, "\n";
echo '  inline transcript: ', var_export($note->hasInlineTranscript(), true), "\n";
echo '  too large inline:  ', var_export($note->transcriptWasTooLarge(), true), "\n\n";

// Works whether the transcript arrived inline or has to be paged.
$transcript = $note->transcript();

echo "First 10 lines\n";
$shown = 0;
foreach ($transcript->each() as $item) {
    printf("  [%s] %s\n", $item->startTime()?->format('H:i:s'), $item->toLine());
    if (++$shown >= 10) {
        break;
    }
}

if ($shown === 0) {
    echo "  (this note has no transcript items)\n";
    exit(0);
}

// ── Working with a loaded transcript ────────────────────────────────────

$full = $note->hasInlineTranscript() ? $transcript : $note->transcript()->fetchAll();

echo "\nSpeakers: ", implode(', ', $full->speakerLabels()), "\n";
echo 'Lines spoken by the note owner: ', count($full->fromMe()), ' of ', count($full), "\n";

$seconds = 0;
foreach ($full->all() as $item) {
    $seconds += $item->durationSeconds() ?? 0;
}
echo 'Total speech: ', round($seconds / 60, 1), " minutes\n";

// The whole thing as text, for a search index or an LLM prompt.
file_put_contents(sys_get_temp_dir() . '/granola-transcript.txt', $full->toText());
echo "\nWritten to ", sys_get_temp_dir(), "/granola-transcript.txt\n";
