<?php

declare(strict_types=1);

namespace Deployer\Auth\Domain;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $passwordHash,
    ) {
    }
}
