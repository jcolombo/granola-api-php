<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// The unit suite never touches the network, so the client-side limiter would
// only add sleep to the run.
\Jcolombo\GranolaApiPhp\Configuration::set('rateLimit.enabled', false);
