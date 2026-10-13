<?php

declare(strict_types=1);

namespace Deployer\Auth\Domain;

final class Session
{
    public function __construct(
        /** sha256 of the cookie token; the raw token is never stored. */
        public readonly string $tokenHash,
        public readonly int $userId,
        public readonly string $csrfToken,
        public readonly string $createdAt,
        public readonly string $lastSeenAt,
    ) {
    }
}
