<?php

declare(strict_types=1);

namespace Deployer\Auth\Infrastructure;

use DateTimeImmutable;
use Deployer\Auth\Domain\User;
use Deployer\Auth\Domain\UserRepository;
use PDO;

final class SqliteUserRepository implements UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function exists(): bool
    {
        return $this->pdo->query('SELECT 1 FROM users LIMIT 1')->fetchColumn() !== false;
    }

    public function createFirst(string $username, string $passwordHash, DateTimeImmutable $now): ?User
    {
        // Single statement, so two concurrent setup requests cannot both create a user.
        $stmt = $this->pdo->prepare('INSERT INTO users (username, password_hash, created_at)
            SELECT ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM users)');
        $stmt->execute([$username, $passwordHash, $now->format('Y-m-d H:i:s')]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }

        return new User((int) $this->pdo->lastInsertId(), $username, $passwordHash);
    }

    public function find(int $id): ?User
    {
        return $this->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function findByUsername(string $username): ?User
    {
        return $this->one('SELECT * FROM users WHERE username = ?', [$username]);
    }

    public function first(): ?User
    {
        return $this->one('SELECT * FROM users ORDER BY id LIMIT 1', []);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$passwordHash, $id]);
    }

    /** @param list<mixed> $params */
    private function one(string $sql, array $params): ?User
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : new User((int) $row['id'], $row['username'], $row['password_hash']);
    }
}
