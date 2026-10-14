<?php

declare(strict_types=1);

namespace Deployer\Tests\Support;

use Deployer\Shared\Application\Port\HttpClient;
use Deployer\Shared\Application\Port\HttpResponse;
use RuntimeException;

/** Records requests and replays queued responses; never touches the network. */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{url: string, body: array<string,mixed>}> */
    public array $requests = [];
    /** @var list<array{url: string, headers: array<string,string>, maxBytes: int}> */
    public array $gets = [];
    /** @var array<string, HttpResponse|RuntimeException> */
    private array $routes = [];
    /** @var list<HttpResponse|RuntimeException> */
    private array $queue = [];

    public function willReturn(int $status, string $body): void
    {
        $this->queue[] = new HttpResponse($status, $body);
    }

    public function willFail(string $message): void
    {
        $this->queue[] = new RuntimeException($message);
    }

    /** Serves GET $url with this response (any number of times). @param array<string,string> $headers */
    public function onGet(string $url, int $status, string $body, array $headers = []): void
    {
        $this->routes[$url] = new HttpResponse($status, $body, array_change_key_case($headers));
    }

    public function failGet(string $url, string $message): void
    {
        $this->routes[$url] = new RuntimeException($message);
    }

    public function get(string $url, array $headers, int $timeoutSeconds, int $maxBytes): HttpResponse
    {
        $this->gets[] = ['url' => $url, 'headers' => $headers, 'maxBytes' => $maxBytes];
        $response = $this->routes[$url] ?? new HttpResponse(404, '{"message":"Not Found"}');
        if ($response instanceof RuntimeException) {
            throw $response;
        }
        if (strlen($response->body) > $maxBytes) {
            throw new RuntimeException(sprintf('Response exceeds %d bytes.', $maxBytes));
        }

        return $response;
    }

    public function postJson(string $url, array $body, int $timeoutSeconds): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'body' => $body];
        $next = array_shift($this->queue) ?? new HttpResponse(200, '{"ok":true,"result":{}}');
        if ($next instanceof RuntimeException) {
            throw $next;
        }

        return $next;
    }
}
