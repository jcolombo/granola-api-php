<?php

declare(strict_types=1);

/**
 * Shared setup for the examples.
 *
 *     export GRANOLA_API_KEY=grn_your_key_here
 *     php examples/02-notes.php
 *
 * A granolaapi.config.json beside the package root works too — see
 * docs/CONFIGURATION.md.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Jcolombo\GranolaApiPhp\Configuration;
use Jcolombo\GranolaApiPhp\Granola;

Configuration::overload(dirname(__DIR__));

$key = getenv('GRANOLA_API_KEY') ?: Configuration::get('connection.apiKey');

if (!is_string($key) || trim($key) === '') {
    fwrite(STDERR, "Set GRANOLA_API_KEY, or connection.apiKey in granolaapi.config.json.\n");
    exit(1);
}

Configuration::set('connection.apiKey', trim($key));

// Examples should fail loudly rather than returning empty collections.
Configuration::set('error.throwOnApiError', true);

return Granola::connect();
