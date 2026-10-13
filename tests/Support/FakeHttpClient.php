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
