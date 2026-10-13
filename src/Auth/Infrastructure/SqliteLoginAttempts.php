<?php

declare(strict_types=1);

namespace Deployer\Auth\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Deployer\Auth\Domain\LoginAttempts;
use PDO;

final class SqliteLoginAttempts implements LoginAttempts
{
    private const TIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function recordFailure(string $ip, DateTimeImmutable $at): void
    {
        $this->pdo->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)')
            ->execute([$ip, $at->format(self::TIME_FORMAT)]);
    }

    public function failuresSince(string $ip, DateTimeImmutable $since): array
    {
        $stmt = $this->pdo->prepare('SELECT attempted_at FROM login_attempts WHERE ip = ? AND attempted_at >= ? ORDER BY attempted_at, id');
        $stmt->execute([$ip, $since->format(self::TIME_FORMAT)]);
        $utc = new DateTimeZone('UTC');

        return array_map(
            static fn (string $at): DateTimeImmutable => new DateTimeImmutable($at, $utc),
            $stmt->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function clear(string $ip): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
    }

    public function prune(DateTimeImmutable $before): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([$before->format(self::TIME_FORMAT)]);
    }
}
