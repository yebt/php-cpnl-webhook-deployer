<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure\Cli\Command;

use Deployer\Shared\Infrastructure\Application;
use Deployer\Shared\Infrastructure\Cli\Command;
use Deployer\Shared\Infrastructure\Cli\Console;
use Deployer\Shared\Infrastructure\Persistence\Migrator;

final class MigrateCommand implements Command
{
    public function __construct(private readonly Application $app)
    {
    }

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Apply pending database migrations';
    }

    public function run(array $args, Console $console): int
    {
        // pdo() already migrates on connect; report the resulting version.
        $version = (new Migrator($this->app->pdo()))->currentVersion();
        $console->line(sprintf('Database schema at version %d.', $version));

        return 0;
    }
}
