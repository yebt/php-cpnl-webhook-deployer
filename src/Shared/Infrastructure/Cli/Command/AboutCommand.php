<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure\Cli\Command;

use Deployer\Shared\Infrastructure\Application;
use Deployer\Shared\Infrastructure\Cli\Command;
use Deployer\Shared\Infrastructure\Cli\Console;
use Deployer\Shared\Infrastructure\Persistence\Migrator;

final class AboutCommand implements Command
{
    public function __construct(private readonly Application $app)
    {
    }

    public function name(): string
    {
        return 'about';
    }

    public function description(): string
    {
        return 'Show version, runtime and storage information';
    }

    public function run(array $args, Console $console): int
    {
        $config = $this->app->config();
        $schema = (new Migrator($this->app->pdo()))->currentVersion();

        $console->line('Deployer ' . Application::VERSION);
        $console->line(sprintf('  %-14s %s', 'PHP', PHP_VERSION . ' (' . PHP_SAPI . ')'));
        $console->line(sprintf('  %-14s %s', 'Artifact', $config->entryScript . ($config->isPhar ? ' (phar)' : ' (source)')));
        $console->line(sprintf('  %-14s %s', 'Config file', $config->configFile ?? '(none)'));
        $console->line(sprintf('  %-14s %s', 'Data dir', $config->dataDir));
        $console->line(sprintf('  %-14s %s', 'Database', $config->databasePath()));
        $console->line(sprintf('  %-14s %d', 'Schema', $schema));
        $console->line(sprintf('  %-14s %s', 'PHP CLI', $config->phpCli));

        return 0;
    }
}
