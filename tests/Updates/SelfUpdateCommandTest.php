<?php

declare(strict_types=1);

namespace Deployer\Tests\Updates;

use Deployer\Shared\Infrastructure\Application;
use Deployer\Shared\Infrastructure\Cli\Console;
use Deployer\Shared\Infrastructure\Config\AppConfig;
use Deployer\Tests\Support\FakeHttpClient;
use Deployer\Tests\Support\FixedClock;
use Deployer\Tests\Support\TempDir;
use Deployer\Tests\Support\TinyPhar;
use PHPUnit\Framework\TestCase;

/**
 * End to end through the CLI: a real (tiny) installed phar is swapped for a
 * real (tiny) release phar served by a fake GitHub API.
 */
final class SelfUpdateCommandTest extends TestCase
{
    use TempDir;

    private const API = 'https://api.github.com/repos/yebt/php-cpnl-webhook-deployer';

    private FakeHttpClient $http;
    private string $installed;
    private string $releaseBytes;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $dir = $this->makeTempDir();
        $this->installed = $dir . '/index.php';
        TinyPhar::build($this->installed, '1.0.0');
        $release = $this->makeTempDir() . '/deployer.phar';
        TinyPhar::build($release, '1.1.0');
        $this->releaseBytes = (string) file_get_contents($release);
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    private function publish(string $tag, ?string $checksum = null): void
    {
        $this->http->onGet(self::API . '/releases/latest', 200, json_encode([
            'tag_name' => $tag,
            'name' => $tag,
            'html_url' => 'https://github.com/yebt/php-cpnl-webhook-deployer/releases/tag/' . $tag,
            'body' => 'Fixes',
            'published_at' => '2026-10-01T00:00:00Z',
            'draft' => false,
            'prerelease' => false,
            'assets' => [
                ['name' => 'deployer.phar', 'url' => self::API . '/releases/assets/1', 'size' => strlen($this->releaseBytes)],
                ['name' => 'deployer.phar.sha256', 'url' => self::API . '/releases/assets/2', 'size' => 80],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->http->onGet(self::API . '/releases/assets/1', 200, $this->releaseBytes);
        $this->http->onGet(self::API . '/releases/assets/2', 200, ($checksum ?? hash('sha256', $this->releaseBytes)) . "  deployer.phar\n");
    }

    /** @return array{int, string, string} */
    private function cli(bool $isPhar, string $version, string ...$args): array
    {
        $app = new Application(
            new AppConfig($this->makeTempDir(), PHP_BINARY, $isPhar ? $this->installed : '/x/bin/deployer', $isPhar),
            new FixedClock(),
            httpClient: $this->http,
            version: $version,
        );
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = $app->runCli(['deployer', 'self-update', ...$args], new Console($out, $err));
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    private function installedVersion(): string
    {
        return trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->installed)));
    }

    public function testCheckReportsAnAvailableUpdateWithoutInstalling(): void
    {
        $this->publish('v1.1.0');

        [$code, $out] = $this->cli(true, '1.0.0', '--check');

        self::assertSame(2, $code);
        self::assertStringContainsString('Update available: 1.0.0 -> 1.1.0', $out);
        self::assertSame('tiny deployer 1.0.0', $this->installedVersion());
    }

    public function testCheckWhenUpToDate(): void
    {
        $this->publish('v1.0.0');

        [$code, $out] = $this->cli(true, '1.0.0', '--check');

        self::assertSame(0, $code);
        self::assertStringContainsString('up to date', $out);
    }

    public function testUpdatesTheRunningPharAndKeepsABackup(): void
    {
        $this->publish('v1.1.0');

        [$code, $out, $err] = $this->cli(true, '1.0.0');

        self::assertSame(0, $code, $err);
        self::assertStringContainsString('Updated 1.0.0 -> 1.1.0', $out);
        self::assertStringContainsString($this->installed . '.bak', $out);
        self::assertSame('tiny deployer 1.1.0', $this->installedVersion());
        self::assertFileExists($this->installed . '.bak');
    }

    public function testChecksumMismatchLeavesTheArtifactUntouched(): void
    {
        $this->publish('v1.1.0', str_repeat('0', 64));
        $before = (string) file_get_contents($this->installed);

        [$code, , $err] = $this->cli(true, '1.0.0');

        self::assertSame(1, $code);
        self::assertStringContainsString('checksum', strtolower($err));
        self::assertSame($before, file_get_contents($this->installed));
        self::assertFileDoesNotExist($this->installed . '.bak');
    }

    public function testRefusesWhenRunningFromSource(): void
    {
        $this->publish('v1.1.0');

        [$code, , $err] = $this->cli(false, 'dev', '--force');

        self::assertSame(1, $code);
        self::assertStringContainsString('phar', $err);
    }

    public function testDevBuildNeedsForce(): void
    {
        $this->publish('v1.1.0');

        [$code, , $err] = $this->cli(true, 'dev');
        self::assertSame(1, $code);
        self::assertStringContainsString('--force', $err);

        [$code] = $this->cli(true, 'dev', '--force');
        self::assertSame(0, $code);
        self::assertSame('tiny deployer 1.1.0', $this->installedVersion());
    }

    public function testRejectsUnknownOptions(): void
    {
        [$code, , $err] = $this->cli(true, '1.0.0', '--yolo');

        self::assertSame(1, $code);
        self::assertStringContainsString('--yolo', $err);
    }
}
