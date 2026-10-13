<?php

declare(strict_types=1);

namespace Deployer\Tests\Shared;

use Deployer\Shared\Infrastructure\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The artifact works both behind a rewrite (.htaccess → clean URLs) and without one,
 * through PATH_INFO URLs such as /index.php/projects/3.
 */
final class RequestPathTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool, bool, string, string}> */
    public static function cases(): iterable
    {
        // uri path, SCRIPT_NAME, rewritten, builtin server => base, path
        yield 'rewrite: deep link' => ['/projects/3', '/index.php', true, false, '', '/projects/3'];
        yield 'rewrite: root' => ['/', '/index.php', true, false, '', '/'];
        yield 'path info: deep link' => ['/index.php/projects/3', '/index.php', false, false, '/index.php', '/projects/3'];
        yield 'path info: api' => ['/index.php/api/me', '/index.php', false, false, '/index.php', '/api/me'];
        yield 'path info: script only' => ['/index.php', '/index.php', false, false, '/index.php', '/'];
        yield 'path info: trailing slash' => ['/index.php/', '/index.php', false, false, '/index.php', '/'];
        yield 'no rewrite: directory index root' => ['/', '/index.php', false, false, '/index.php', '/'];
        yield 'builtin server: root keeps clean urls' => ['/', '/index.php', false, true, '', '/'];
        yield 'builtin server: deep link' => ['/runs/9', '/index.php', false, true, '', '/runs/9'];
        yield 'subdirectory with rewrite' => ['/deploy/projects/3', '/deploy/index.php', true, false, '/deploy', '/projects/3'];
        yield 'subdirectory path info' => ['/deploy/index.php/runs/1', '/deploy/index.php', false, false, '/deploy/index.php', '/runs/1'];
        yield 'subdirectory root without rewrite' => ['/deploy/', '/deploy/index.php', false, false, '/deploy/index.php', '/'];
        // php -S reports the request path as SCRIPT_NAME when its docroot has no matching script.
        yield 'builtin server: non-php script name ignored' => ['/projects/x', '/projects/x', false, true, '', '/projects/x'];
        yield 'prefix lookalike is not the script' => ['/index.phpx/a', '/index.php', true, false, '', '/index.phpx/a'];
    }

    #[DataProvider('cases')]
    public function testSplitsBasePathFromRoutePath(string $uri, string $script, bool $rewritten, bool $builtin, string $base, string $path): void
    {
        self::assertSame([$base, $path], Request::splitPath($uri, $script, $rewritten, $builtin));
    }
}
