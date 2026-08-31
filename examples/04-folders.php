<?php

declare(strict_types=1);

/**
 * 04 — Folders.
 *
 * Granola has no "get one folder" endpoint and returns the hierarchy flat, as a
 * parent_folder_id on each folder. Fetch them all once, then work locally.
 */

use Jcolombo\GranolaApiPhp\Entity\Resource\Folder;
use Jcolombo\GranolaApiPhp\Entity\Resource\Note;

require __DIR__ . '/bootstrap.php';

$folders = Folder::all();

echo 'Folders: ', count($folders), "\n\n";

if ($folders->isEmpty()) {
    echo "This key can see no folders.\n";
    exit(0);
}

// ── The hierarchy ───────────────────────────────────────────────────────

echo "Tree\n";
printTree($folders->tree());

/**
 * @param list<array{folder: Folder, children: list<mixed>}> $nodes
 */
function printTree(array $nodes, int $depth = 0): void
{
    foreach ($nodes as $node) {
        printf("  %s%s  %s\n", str_repeat('  ', $depth), (string) $node['folder']->name(), $node['folder']->id());
        printTree($node['children'], $depth + 1);
    }
}

// ── Paths and descendants ───────────────────────────────────────────────

$deepest = $folders->last();

echo "\nPath to “", (string) $deepest->name(), "”\n";
echo '  ', $folders->pathOf($deepest), "\n";

$root = $folders->roots()[0] ?? null;
if ($root !== null) {
    echo "\nEverything under “", (string) $root->name(), "”\n";
    foreach ($folders->descendantsOf($root) as $descendant) {
        echo '  ', $folders->pathOf($descendant), "\n";
    }

    // The server-side filter already includes subfolders, so there is no need
    // to iterate descendants when listing notes.
    $notes = Note::list()->inFolder($root)->pageSize(5)->fetch();
    echo "\nNotes in that folder and its children: ", count($notes), " (first page)\n";
}
