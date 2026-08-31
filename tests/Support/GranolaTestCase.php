<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Tests\Support;

use Jcolombo\GranolaApiPhp\Cache\Cache;
use Jcolombo\GranolaApiPhp\Configuration;
use Jcolombo\GranolaApiPhp\Granola;
use Jcolombo\GranolaApiPhp\Utility\Log;
use Jcolombo\GranolaApiPhp\Utility\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Resets every singleton between tests, so configuration, connections, cache
 * and limiter state never leak from one case into the next.
 */
abstract class GranolaTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSdk();
    }

    protected function tearDown(): void
    {
        $this->resetSdk();
        parent::tearDown();
    }

    private function resetSdk(): void
    {
        Granola::disconnect();
        Configuration::reset();
        Cache::reset();
        Log::reset();
        RateLimiter::reset();

        Configuration::set('rateLimit.enabled', false);
    }
}
