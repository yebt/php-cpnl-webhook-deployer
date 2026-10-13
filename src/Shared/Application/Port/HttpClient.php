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
}
