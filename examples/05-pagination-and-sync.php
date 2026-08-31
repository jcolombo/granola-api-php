<?php

declare(strict_types=1);

/**
 * 05 — Pagination, and a resumable sync.
 *
 * Granola pages with an opaque cursor and warns that a page may hold fewer
 * items than page_size while still not being the last one — so paging is driven
 * by hasMore, never by counting results.
 */

use Jcolombo\GranolaApiPhp\Entity\Resource\Note;

require __DIR__ . '/bootstrap.php';

// ── One page at a time ──────────────────────────────────────────────────

$notes = Note::list()->pageSize(5);

$notes->fetch();
echo 'Page 1: ', count($notes), " notes\n";

if ($notes->hasMore()) {
    $notes->fetchNext();
    echo 'After page 2: ', count($notes), " notes loaded in total\n";
}

// ── Streaming a whole archive at constant memory ────────────────────────

$streamed = Note::list()->pageSize(30)->maxPages(3);

$count = 0;
foreach ($streamed->each() as $note) {
    $count++;
}

echo "\nStreamed ", $count, ' notes across ', $streamed->pagesFetched(), " page(s)\n";
echo 'Resident after the walk: ', count($streamed), " (one page)\n";
echo 'Peak memory: ', round(memory_get_peak_usage(true) / 1_048_576, 1), " MB\n";

// ── A resumable sync ────────────────────────────────────────────────────

/**
 * The pattern a cron job wants: commit the cursor after every page, so an
 * interruption costs one page rather than the whole run, and a failed page
 * never advances past unread notes.
 */
$stateFile = sys_get_temp_dir() . '/granola-sync-cursor.txt';
$cursor = is_file($stateFile) ? (file_get_contents($stateFile) ?: null) : null;

$sync = Note::list()->pageSize(30);
$cursor !== null
    ? $sync->withCursor($cursor)
    : $sync->updatedAfter(new DateTimeImmutable('-7 days'));

$processed = 0;
$budget = 60;   // stop well inside the rate limit

do {
    $sync->fetch();

    foreach ($sync as $note) {
        // Real work goes here — upsert into your own store.
        $processed++;
    }

    // Commit per page.
    $sync->cursor() === null
        ? @unlink($stateFile)
        : file_put_contents($stateFile, $sync->cursor());
} while ($sync->hasMore() && $processed < $budget);

echo "\nSync processed ", $processed, " notes\n";
echo $sync->hasMore()
    ? "  more remain — resume from the stored cursor on the next run\n"
    : "  caught up; cursor cleared\n";
