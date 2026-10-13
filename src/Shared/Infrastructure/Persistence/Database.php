<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure\Persistence;

use PDO;
use RuntimeException;

final class Database
{
    /**
     * Opens the SQLite database, creating its directory if needed. WAL mode lets the
     * web front controller read while detached runners write logs concurrently.
     */
    public static function connect(string $path): PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create data directory %s', $dir));
        }
        // Defense in depth if the data dir is misconfigured inside a web root.
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n");
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 10000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }
}
