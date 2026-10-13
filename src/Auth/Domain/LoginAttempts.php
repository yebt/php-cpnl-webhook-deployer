<?php

declare(strict_types=1);

namespace Deployer\Auth\Domain;

use DateTimeImmutable;

/** Failed login attempts per client IP, for throttling. */
interface LoginAttempts
{
    public function recordFailure(string $ip, DateTimeImmutable $at): void;

    /** @return list<DateTimeImmutable> failures since $since, oldest first */
    public function failuresSince(string $ip, DateTimeImmutable $since): array;

    public function clear(string $ip): void;

    public function prune(DateTimeImmutable $before): void;
}
