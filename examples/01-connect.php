<?php

declare(strict_types=1);

/**
 * 01 — Connecting.
 *
 * The default key comes from configuration, so connect() usually takes no
 * arguments, and resources resolve the connection themselves.
 */

use Jcolombo\GranolaApiPhp\Entity\Resource\Folder;
use Jcolombo\GranolaApiPhp\Granola;

$granola = require __DIR__ . '/bootstrap.php';

echo "Connected\n";
echo '  name:        ', $granola->getName(), "\n";
echo '  url:         ', $granola->getUrl(), "\n";
echo '  fingerprint: ', $granola->getFingerprint(), "  (safe to log — never the key)\n\n";

// Resources find the default connection on their own.
$folders = Folder::all();
echo 'Folders visible to this key: ', count($folders), "\n\n";

// The same key always returns the same instance.
var_dump($granola === Granola::connect());
var_dump($granola === Granola::connection());
