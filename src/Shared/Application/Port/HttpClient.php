<?php

declare(strict_types=1);

namespace Deployer\Shared\Application\Port;

use RuntimeException;

/** Outbound HTTP, kept behind a port so tests never touch the network. */
interface HttpClient
{
    /**
     * @param array<string,mixed> $body encoded as JSON
     * @throws RuntimeException on transport errors (DNS, TLS, timeout...)
     */
    public function postJson(string $url, array $body, int $timeoutSeconds): HttpResponse;

    /**
     * Plain GET. Redirects are NOT followed: callers inspect 3xx + Location and
     * decide (e.g. to enforce a host allow-list).
     *
     * @param array<string,string> $headers name => value
     * @throws RuntimeException on transport errors or when the body exceeds $maxBytes
     */
    public function get(string $url, array $headers, int $timeoutSeconds, int $maxBytes): HttpResponse;
}
