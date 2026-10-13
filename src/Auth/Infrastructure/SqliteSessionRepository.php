<?php

declare(strict_types=1);

namespace Deployer\Auth\Infrastructure;

use DateTimeImmutable;
use Deployer\Auth\Domain\Session;
use Deployer\Auth\Domain\SessionRepository;
use PDO;

final class SqliteSessionRepository implements SessionRepository
{
    private const TIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function save(Session $session): void
    {
        $this->pdo->prepare('INSERT INTO sessions (token_hash, user_id, csrf_token, created_at, last_seen_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$session->tokenHash, $session->userId, $session->csrfToken, $session->createdAt, $session->lastSeenAt]);
    }

    public function find(string $tokenHash): ?Session
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE token_hash = ?');
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();

        return $row === false ? null : new Session(
            $row['token_hash'],
            (int) $row['user_id'],
            $row['csrf_token'],
            $row['created_at'],
            $row['last_seen_at'],
        );
    }

    public function touch(string $tokenHash, DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE sessions SET last_seen_at = ? WHERE token_hash = ?')
            ->execute([$now->format(self::TIME_FORMAT), $tokenHash]);
    }

    public function delete(string $tokenHash): void
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([$tokenHash]);
    }

    public function deleteForUser(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$userId]);
    }

    public function deleteExpired(DateTimeImmutable $idleBefore, DateTimeImmutable $createdBefore): void
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE last_seen_at < ? OR created_at < ?')
            ->execute([$idleBefore->format(self::TIME_FORMAT), $createdBefore->format(self::TIME_FORMAT)]);
    }
}
