<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure\Cli;

use Deployer\Shared\Infrastructure\Application;
use Deployer\Shared\Infrastructure\Cli\Command;
use Deployer\Shared\Infrastructure\Cli\Console;

final class RunnerCommand implements Command
{
    public function __construct(private readonly Application $app)
    {
    }

    public function name(): string
    {
        return 'runner';
    }

    public function description(): string
    {
        return 'Drain the queued runs of <project> (id or slug); exits if another runner holds the lock';
    }

    public function run(array $args, Console $console): int
    {
        $project = ProjectArgument::resolve($this->app->projects(), $args[0] ?? null);
        $executed = ($this->app->drainProjectQueue())($project->id);
        $console->line(sprintf('Runner for project "%s" executed %d run(s).', $project->slug, $executed));

        return 0;
    }
}
