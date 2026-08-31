<?php

declare(strict_types=1);

/**
 * 02 — Listing and reading notes.
 *
 * A listing returns the summary shape only. Summaries, attendees, the calendar
 * event and the transcript arrive with Note::find(), so walking a list is cheap
 * and detail is paid for per note.
 */

use Jcolombo\GranolaApiPhp\Entity\Resource\Note;

require __DIR__ . '/bootstrap.php';

// ── One page ────────────────────────────────────────────────────────────

$notes = Note::list()->pageSize(10)->fetch();

echo "Most recent notes\n";
foreach ($notes as $note) {
    printf("  %s  %-40s  %s\n", $note->id(), mb_substr((string) $note->title, 0, 40), $note->created_at->format('Y-m-d'));
}

echo "\n  hasMore: ", var_export($notes->hasMore(), true), "\n";
echo '  cursor:  ', $notes->cursor() ?? 'null', "\n\n";

if ($notes->isEmpty()) {
    echo "No notes visible to this key — nothing further to show.\n";
    echo "Granola only returns notes that already have a generated summary and transcript.\n";
    exit(0);
}

// ── Filtering ───────────────────────────────────────────────────────────

$recent = Note::list()
    ->updatedAfter(new DateTimeImmutable('-30 days'))
    ->pageSize(30)
    ->fetch();

echo 'Updated in the last 30 days: ', count($recent), "\n\n";

// ── Full detail ─────────────────────────────────────────────────────────

$note = Note::find($notes->first()->id());

echo "Note detail\n";
echo '  title:     ', (string) $note->title, "\n";
echo '  owner:     ', $note->owner()?->displayName(), "\n";
echo '  url:       ', (string) $note->web_url, "\n";
echo '  attendees: ', implode(', ', $note->attendeeEmails()) ?: '(none)', "\n";
echo '  folders:   ', implode(', ', $note->folderIds()) ?: '(none)', "\n";

if ($event = $note->calendarEvent()) {
    echo '  meeting:   ', (string) $event->eventTitle, "\n";
    echo '  scheduled: ', $event->scheduledStartTime?->format('Y-m-d H:i'), ' for ', $event->scheduledMinutes(), " min\n";
    echo '  external:  ', var_export($event->isExternal(), true), "\n";
}

echo "\nSummary\n", mb_substr((string) $note->summary(), 0, 500), "\n";
