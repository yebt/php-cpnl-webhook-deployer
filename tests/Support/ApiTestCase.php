<?php

declare(strict_types=1);

namespace Deployer\Tests\Support;

use Deployer\Shared\Infrastructure\Application;
use Deployer\Shared\Infrastructure\Config\AppConfig;
use Deployer\Shared\Infrastructure\Http\Request;
use Deployer\Shared\Infrastructure\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Drives the HTTP application in-process with a tiny cookie jar, like a browser.
 */
abstract class ApiTestCase extends TestCase
{
    use TempDir;

    protected Application $app;
    protected FixedClock $clock;
    /** @var array<string,string> */
    protected array $cookies = [];
    protected ?string $csrf = null;

    protected function setUp(): void
    {
        $this->clock = new FixedClock();
        $this->app = new Application(
            new AppConfig($this->makeTempDir(), PHP_BINARY, dirname(__DIR__, 2) . '/bin/deployer', false),
            $this->clock,
        );
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,string> $query
     */
    protected function call(
        string $method,
        string $path,
        mixed $json = null,
        array $headers = [],
        array $query = [],
        string $ip = '10.0.0.1',
        bool $https = false,
        ?string $rawBody = null,
    ): Response {
        if ($this->csrf !== null && !isset($headers['x-csrf-token'])) {
            $headers['x-csrf-token'] = $this->csrf;
        }
        $body = $rawBody ?? ($json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR));
        if ($body !== '') {
            $headers['content-type'] ??= 'application/json';
        }
        $response = $this->app->handle(new Request(
            $method,
            $path,
            $query,
            array_change_key_case($headers),
            $body,
            cookies: $this->cookies,
            remoteAddr: $ip,
            https: $https,
        ));
        foreach ($response->cookies as $cookie) {
            [$pair] = explode(';', $cookie, 2);
            [$name, $value] = explode('=', $pair, 2);
            if ($value === '' || str_contains(strtolower($cookie), 'max-age=0')) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = rawurldecode($value);
            }
        }

        return $response;
    }

    /** @return array<string,mixed> */
    protected function json(Response $response): array
    {
        return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
    }

    protected function setUpAdmin(string $username = 'admin', string $password = 'correct horse battery'): void
    {
        $response = $this->call('POST', '/api/setup', ['username' => $username, 'password' => $password]);
        self::assertSame(201, $response->status, $response->body);
        $this->csrf = $this->json($response)['csrf_token'];
    }
}
