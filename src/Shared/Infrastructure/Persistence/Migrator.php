<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure\Persistence;

use PDO;
use Throwable;

final class Migrator
{
    /** @param array<int, list<string>>|null $migrations */
    public function __construct(private readonly PDO $pdo, private readonly ?array $migrations = null)
    {
    }

    /** @return list<int> versions applied by this call */
    public function migrate(): array
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version INTEGER PRIMARY KEY,
            applied_at TEXT NOT NULL
        )');

        $migrations = $this->migrations ?? Migrations::all();
        ksort($migrations);
        $applied = [];
        foreach ($migrations as $version => $statements) {
            if ($version <= $this->currentVersion()) {
                continue;
            }
            // IMMEDIATE takes the write lock up front so concurrent processes
            // (web request + runner) cannot both apply the same version.
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                if ($version <= $this->currentVersion()) {
                    $this->pdo->exec('COMMIT');
                    continue;
                }
                foreach ($statements as $sql) {
                    $this->pdo->exec($sql);
                }
                $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)');
                $stmt->execute([$version, gmdate('Y-m-d H:i:s')]);
                $this->pdo->exec('COMMIT');
                $applied[] = $version;
            } catch (Throwable $e) {
                $this->pdo->exec('ROLLBACK');
                throw $e;
            }
        }

        return $applied;
    }

    public function currentVersion(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn();
    }
}
