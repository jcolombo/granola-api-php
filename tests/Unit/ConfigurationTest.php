<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Tests\Unit;

use Jcolombo\GranolaApiPhp\Configuration;
use Jcolombo\GranolaApiPhp\Tests\Support\GranolaTestCase;

final class ConfigurationTest extends GranolaTestCase
{
    private string $tempDir = '';

    protected function tearDown(): void
    {
        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            foreach ((array) glob($this->tempDir . '/*') as $file) {
                if (is_string($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testPackagedDefaultsAreAvailableWithoutAnyOverride(): void
    {
        self::assertSame('https://public-api.granola.ai/', Configuration::get('connection.url'));
        self::assertNull(Configuration::get('connection.apiKey'));
        self::assertSame(25, Configuration::get('rateLimit.burstLimit'));
        self::assertSame(300, Configuration::get('rateLimit.perMinute'));
        self::assertTrue(Configuration::get('notes.autoFallbackLargeTranscript'));
    }

    public function testAnOverrideFileMergesDeeplyRatherThanReplacingBranches(): void
    {
        $path = $this->writeOverride(['connection' => ['apiKey' => 'grn_from_file']]);

        Configuration::load($path);

        self::assertSame('grn_from_file', Configuration::get('connection.apiKey'));
        self::assertSame(
            'https://public-api.granola.ai/',
            Configuration::get('connection.url'),
            'sibling keys under connection survive the merge'
        );
    }

    public function testOverloadFindsTheConventionalFilenameInADirectory(): void
    {
        $this->writeOverride(['webhook' => ['toleranceSeconds' => 90]]);

        Configuration::overload($this->tempDir);

        self::assertSame(90, Configuration::get('webhook.toleranceSeconds'));
    }

    public function testOverloadIsSilentWhenThereIsNothingToLoad(): void
    {
        Configuration::overload('/nonexistent/path/granolaapi.config.json');

        self::assertSame('https://public-api.granola.ai/', Configuration::get('connection.url'));
    }

    public function testValuesCanBeSetAtRuntimeAndReported(): void
    {
        Configuration::set('connection.apiKey', 'grn_runtime');

        self::assertSame('grn_runtime', Configuration::get('connection.apiKey'));
        self::assertTrue(Configuration::has('connection.apiKey'));
        self::assertSame('fallback', Configuration::get('nothing.here', 'fallback'));
    }

    public function testLoadedPathsRecordEveryMergedFileInOrder(): void
    {
        $path = $this->writeOverride(['devMode' => true]);
        Configuration::load($path);

        $paths = Configuration::loadedPaths();

        self::assertCount(2, $paths);
        self::assertStringEndsWith('default.granolaapi.config.json', $paths[0]);
        self::assertSame($path, $paths[1]);
    }

    public function testInvalidJsonIsReportedRatherThanSilentlyIgnored(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/granola-config-' . uniqid();
        mkdir($this->tempDir);
        $path = $this->tempDir . '/granolaapi.config.json';
        file_put_contents($path, '{ not json');

        $this->expectException(\JsonException::class);

        Configuration::load($path);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeOverride(array $data): string
    {
        $this->tempDir = sys_get_temp_dir() . '/granola-config-' . uniqid();
        mkdir($this->tempDir);
        $path = $this->tempDir . '/granolaapi.config.json';
        file_put_contents($path, (string) json_encode($data));
        return $path;
    }
}
