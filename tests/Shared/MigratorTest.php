<?php

declare(strict_types=1);

namespace Deployer\Tests\Shared;

use Deployer\Shared\Infrastructure\Persistence\Database;
use Deployer\Shared\Infrastructure\Persistence\Migrator;
use Deployer\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    use TempDir;

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    public function testCreatesSchemaAndIsIdempotent(): void
    {
        $dir = $this->makeTempDir() . '/nested/data';
        $pdo = Database::connect($dir . '/deployer.sqlite');
        $migrator = new Migrator($pdo);

        self::assertSame([1], $migrator->migrate());
        self::assertSame([], $migrator->migrate());
        self::assertSame(1, $migrator->currentVersion());

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['users', 'notifiers', 'projects', 'runs', 'run_steps', 'run_logs', 'schema_migrations'] as $table) {
            self::assertContains($table, $tables);
        }
        self::assertSame('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
        self::assertFileExists($dir . '/.htaccess');
    }

    public function testFailedMigrationIsRolledBack(): void
    {
        $pdo = Database::connect($this->makeTempDir() . '/db.sqlite');
        $migrator = new Migrator($pdo, [
            1 => ['CREATE TABLE a (id INTEGER)'],
            2 => ['CREATE TABLE b (id INTEGER)', 'THIS IS NOT SQL'],
        ]);

        try {
            $migrator->migrate();
            self::fail('Expected the broken migration to throw');
        } catch (\PDOException) {
        }

        self::assertSame(1, $migrator->currentVersion());
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotContains('b', $tables);
    }
}
