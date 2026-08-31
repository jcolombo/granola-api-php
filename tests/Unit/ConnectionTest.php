<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Tests\Unit;

use Jcolombo\GranolaApiPhp\Configuration;
use Jcolombo\GranolaApiPhp\Exception\ConfigurationException;
use Jcolombo\GranolaApiPhp\Granola;
use Jcolombo\GranolaApiPhp\Tests\Support\GranolaTestCase;

final class ConnectionTest extends GranolaTestCase
{
    public function testConnectWithNoArgumentsUsesTheConfiguredDefaultKey(): void
    {
        Configuration::set('connection.apiKey', 'grn_default_key');

        $granola = Granola::connect();

        self::assertSame('https://public-api.granola.ai/', $granola->getUrl());
        self::assertTrue(Granola::hasConnection());
    }

    public function testConnectWithoutAnyKeyConfiguredThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/No Granola API key available/');

        Granola::connect();
    }

    public function testTheSameKeyAlwaysReturnsTheSameInstance(): void
    {
        $first = Granola::connect('grn_key_a');
        $second = Granola::connect('grn_key_a');

        self::assertSame($first, $second);
    }

    public function testAdditionalKeysLiveAlongsideTheDefaultAndAreAddressedByName(): void
    {
        Configuration::set('connection.apiKey', 'grn_default_key');

        $default = Granola::connect();
        $workspace = Granola::connect('grn_workspace_key', 'workspace');

        self::assertNotSame($default, $workspace);
        self::assertSame($workspace, Granola::connection('workspace'));
        self::assertSame($default, Granola::connection(), 'the first connection stays the default');
        self::assertSame('workspace', $workspace->getName());
    }

    public function testAnUnknownConnectionNameThrows(): void
    {
        Granola::connect('grn_key_a', 'primary');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/No Granola connection named 'nope'/");

        Granola::connection('nope');
    }

    public function testSetDefaultPromotesANamedConnection(): void
    {
        Granola::connect('grn_key_a', 'first');
        $second = Granola::connect('grn_key_b', 'second');

        Granola::setDefault('second');

        self::assertSame($second, Granola::connection());
    }

    public function testDisconnectByNameForgetsBothTheAliasAndTheConnection(): void
    {
        Granola::connect('grn_key_a', 'first');
        Granola::connect('grn_key_b', 'second');

        Granola::disconnect('first');

        self::assertFalse(Granola::hasConnection('first'));
        self::assertTrue(Granola::hasConnection('second'));
    }

    public function testDisconnectingTheDefaultPromotesARemainingConnection(): void
    {
        Granola::connect('grn_key_a', 'first');
        $second = Granola::connect('grn_key_b', 'second');

        Granola::disconnect('first');

        self::assertSame($second, Granola::connection());
    }

    public function testTheFingerprintNeverExposesTheKey(): void
    {
        $granola = Granola::connect('grn_super_secret_value_9999');

        $fingerprint = $granola->getFingerprint();

        self::assertStringNotContainsString('super_secret', $fingerprint);
        self::assertStringEndsWith('9999', $fingerprint);
    }

    public function testConnectionUrlCanBeOverriddenPerConnection(): void
    {
        $granola = Granola::connect('grn_key_a', null, 'https://proxy.internal/granola/');

        self::assertSame('https://proxy.internal/granola/', $granola->getUrl());
    }
}
