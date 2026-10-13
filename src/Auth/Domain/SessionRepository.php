<?php

declare(strict_types=1);

namespace Deployer\Auth\Domain;

use DateTimeImmutable;

interface SessionRepository
{
    public function save(Session $session): void;

    public function find(string $tokenHash): ?Session;

    public function touch(string $tokenHash, DateTimeImmutable $now): void;

    public function delete(string $tokenHash): void;

    public function deleteForUser(int $userId): void;

    public function deleteExpired(DateTimeImmutable $idleBefore, DateTimeImmutable $createdBefore): void;
}
