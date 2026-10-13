<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure\Cli;

use Deployer\Shared\Infrastructure\Application;
use Deployer\Shared\Infrastructure\Cli\Command;
use Deployer\Shared\Infrastructure\Cli\Console;

final class RunnerSweepCommand implements Command
{
    public function __construct(private readonly Application $app)
    {
    }

    public function name(): string
    {
        return 'runner:sweep';
    }

    public function description(): string
    {
        return 'Fail runs whose runner died and restart runners for orphaned queues (for cron)';
    }

    public function run(array $args, Console $console): int
    {
        $report = ($this->app->sweepRunners())();
        $console->line(sprintf(
            'Failed stale runs: %s. Launched runners for projects: %s.',
            $report['failedRuns'] === [] ? 'none' : implode(', ', $report['failedRuns']),
            $report['launchedProjects'] === [] ? 'none' : implode(', ', $report['launchedProjects']),
        ));

        return 0;
    }
}
