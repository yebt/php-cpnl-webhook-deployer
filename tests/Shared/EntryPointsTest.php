<?php

declare(strict_types=1);

namespace Deployer\Tests\Shared;

use Deployer\Shared\Infrastructure\Application;
use Deployer\Shared\Infrastructure\Cli\Console;
use Deployer\Shared\Infrastructure\Config\AppConfig;
use Deployer\Shared\Infrastructure\Http\Request;
use Deployer\Shared\Infrastructure\Persistence\Migrations;
use Deployer\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class EntryPointsTest extends TestCase
{
    use TempDir;

    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application(new AppConfig($this->makeTempDir(), PHP_BINARY, '/x/bin/deployer', false));
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    /** @return array{int, string, string} */
    private function cli(string ...$args): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = $this->app->runCli(['deployer', ...$args], new Console($out, $err));
        rewind($out);
        rewind($err);

        return [$code, stream_get_contents($out), stream_get_contents($err)];
    }

    public function testAboutCommandReportsVersionAndSchema(): void
    {
        [$code, $out] = $this->cli('about');

        self::assertSame(0, $code);
        self::assertStringContainsString('Deployer ' . Application::VERSION, $out);
        self::assertMatchesRegularExpression('/Schema\s+' . max(array_keys(Migrations::all())) . '\b/', $out);
    }

    public function testUnknownCommandFails(): void
    {
        [$code, , $err] = $this->cli('does-not-exist');

        self::assertSame(1, $code);
        self::assertStringContainsString('Unknown command', $err);
    }

    public function testHttpHealthAndNotFound(): void
    {
        $ok = $this->app->handle(new Request('GET', '/api/health'));
        self::assertSame(200, $ok->status);
        self::assertSame('ok', json_decode($ok->body, true)['status']);

        self::assertSame(404, $this->app->handle(new Request('GET', '/missing'))->status);
        self::assertSame(405, $this->app->handle(new Request('POST', '/api/health'))->status);
    }
}
