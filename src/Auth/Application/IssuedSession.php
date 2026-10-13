<?php

declare(strict_types=1);

namespace Deployer\Auth\Application;

use Deployer\Auth\Domain\Session;

final class IssuedSession
{
    public function __construct(
        /** Raw token for the cookie; only returned at issue time. */
        public readonly string $token,
        public readonly Session $session,
    ) {
    }
}
