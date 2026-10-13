<?php

declare(strict_types=1);

namespace Deployer\Auth\Domain;

use DateTimeImmutable;

interface UserRepository
{
    public function exists(): bool;

    /** Creates the user only when the table is empty (atomic). Returns null if a user already exists. */
    public function createFirst(string $username, string $passwordHash, DateTimeImmutable $now): ?User;

    public function find(int $id): ?User;

    public function findByUsername(string $username): ?User;

    /** The single administrator, if set up. */
    public function first(): ?User;

    public function updatePassword(int $id, string $passwordHash): void;
}
