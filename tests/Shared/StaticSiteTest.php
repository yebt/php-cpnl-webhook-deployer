<?php

declare(strict_types=1);

namespace Deployer\Tests\Shared;

use Deployer\Shared\Infrastructure\Application;
use Deployer\Shared\Infrastructure\Config\AppConfig;
use Deployer\Shared\Infrastructure\Http\Request;
use Deployer\Shared\Infrastructure\Http\Response;
use Deployer\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * The front controller serves the built SPA: hashed assets with long-lived caching,
 * index.html for every other non-API GET (history-mode deep links).
 */
final class StaticSiteTest extends TestCase
{
    use TempDir;

    private const INDEX = "<!doctype html>\n<html><head><base href=\"/\" /><script type=\"module\" src=\"./assets/app-3f2a1b.js\"></script></head><body><div id=\"app\"></div></body></html>\n";

    private string $webRoot;
    private Application $app;

    protected function setUp(): void
    {
        $this->webRoot = $this->makeTempDir();
        mkdir($this->webRoot . '/assets');
        file_put_contents($this->webRoot . '/index.html', self::INDEX);
        file_put_contents($this->webRoot . '/assets/app-3f2a1b.js', 'console.log("app");');
        file_put_contents($this->webRoot . '/assets/app-3f2a1b.css', 'body{}');
        file_put_contents($this->webRoot . '/favicon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $this->app = new Application(
            new AppConfig($this->makeTempDir(), PHP_BINARY, '/x/bin/deployer', false),
            webRoot: $this->webRoot,
        );
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    /** @param array<string,string> $headers */
    private function get(string $path, string $method = 'GET', string $basePath = '', array $headers = []): Response
    {
        return $this->app->handle(new Request($method, $path, [], $headers, basePath: $basePath));
    }

    public function testRootServesTheSpaShellWithoutLongCaching(): void
    {
        $response = $this->get('/');

        self::assertSame(200, $response->status);
        self::assertSame('text/html; charset=utf-8', $response->headers['Content-Type']);
        self::assertSame('no-cache', $response->headers['Cache-Control']);
        self::assertStringContainsString('<div id="app">', $response->body);
        self::assertStringContainsString('<base href="/" />', $response->body);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        self::assertStringContainsString("frame-ancestors 'none'", $response->headers['Content-Security-Policy']);
    }

    public function testHashedAssetsAreServedWithTheirTypeAndImmutableCaching(): void
    {
        $js = $this->get('/assets/app-3f2a1b.js');
        self::assertSame(200, $js->status);
        self::assertSame('text/javascript; charset=utf-8', $js->headers['Content-Type']);
        self::assertSame('public, max-age=31536000, immutable', $js->headers['Cache-Control']);
        self::assertSame('console.log("app");', $js->body);

        $css = $this->get('/assets/app-3f2a1b.css');
        self::assertSame('text/css; charset=utf-8', $css->headers['Content-Type']);
    }

    public function testUnhashedFilesAreRevalidated(): void
    {
        $svg = $this->get('/favicon.svg');

        self::assertSame(200, $svg->status);
        self::assertSame('image/svg+xml', $svg->headers['Content-Type']);
        self::assertSame('no-cache', $svg->headers['Cache-Control']);
    }

    public function testDeepLinksFallBackToTheSpaShell(): void
    {
        foreach (['/projects/x', '/runs/42', '/login', '/projects/3/instructions'] as $path) {
            $response = $this->get($path);
            self::assertSame(200, $response->status, $path);
            self::assertStringContainsString('<div id="app">', $response->body, $path);
        }
    }

    public function testMissingFilesWithAnExtensionAre404(): void
    {
        self::assertSame(404, $this->get('/assets/missing-123.js')->status);
        self::assertSame(404, $this->get('/robots.txt')->status);
    }

    public function testPathTraversalAndHiddenFilesAreRejected(): void
    {
        file_put_contents($this->webRoot . '/.env', 'SECRET=1');
        foreach (['/../index.html', '/assets/../../etc/passwd', '/.env', '/assets/%2e%2e/index.html', "/a\0b.js"] as $path) {
            $response = $this->get($path);
            self::assertNotSame('SECRET=1', $response->body, $path);
            self::assertStringNotContainsString('root:', $response->body, $path);
        }
        self::assertSame(404, $this->get('/.env')->status);
    }

    public function testApiAndWebhookPathsAreNeverServedTheShell(): void
    {
        $api = $this->get('/api/projects');
        self::assertSame(401, $api->status);
        self::assertSame('application/json', $api->headers['Content-Type']);

        self::assertSame(404, $this->get('/api')->status);
        $hook = $this->get('/hooks/demo');
        self::assertSame(405, $hook->status);
        self::assertSame('application/json', $hook->headers['Content-Type']);
    }

    public function testOtherMethodsOnSpaPathsAreNotServed(): void
    {
        self::assertSame(404, $this->get('/projects/x', 'POST')->status);
    }

    public function testHeadReturnsHeadersWithoutBody(): void
    {
        $response = $this->get('/assets/app-3f2a1b.js', 'HEAD');

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
        self::assertSame('text/javascript; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testMatchingEtagAnswers304(): void
    {
        $first = $this->get('/');
        $etag = $first->headers['ETag'];

        $second = $this->get('/', headers: ['if-none-match' => $etag]);
        self::assertSame(304, $second->status);
        self::assertSame('', $second->body);
    }

    public function testShellBaseFollowsTheRequestBasePath(): void
    {
        $response = $this->get('/projects/x', basePath: '/index.php');

        self::assertStringContainsString('<base href="/index.php/" />', $response->body);
        self::assertStringNotContainsString('<base href="/" />', $response->body);
    }

    public function testMissingBuildAnswers404WithAHint(): void
    {
        $app = new Application(
            new AppConfig($this->makeTempDir(), PHP_BINARY, '/x/bin/deployer', false),
            webRoot: $this->webRoot . '/does-not-exist',
        );

        $response = $app->handle(new Request('GET', '/'));
        self::assertSame(404, $response->status);
        self::assertStringContainsString('not built', $response->body);
    }
}
