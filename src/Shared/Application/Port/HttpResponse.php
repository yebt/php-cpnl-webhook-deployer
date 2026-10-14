<?php

declare(strict_types=1);

namespace Deployer\Shared\Application\Port;

final class HttpResponse
{
    /** @param array<string,string> $headers lower-cased names; last value wins */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
