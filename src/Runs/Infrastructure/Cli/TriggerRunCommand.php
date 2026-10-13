<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure\Cli;

use Deployer\Shared\Infrastructure\Application;
use Deployer\Shared\Infrastructure\Cli\Command;
use Deployer\Shared\Infrastructure\Cli\Console;

final class TriggerRunCommand implements Command
{
    public function __construct(private readonly Application $app)
    {
    }

    public function name(): string
    {
        return 'run:trigger';
    }

    public function description(): string
    {
        return 'Queue a run for <project> (id or slug) and start its runner';
    }

    public function run(array $args, Console $console): int
    {
        $project = ProjectArgument::resolve($this->app->projects(), $args[0] ?? null);
        $runId = ($this->app->enqueueRun())($project->id, 'cli');
        $console->line(sprintf('Queued run #%d for project "%s".', $runId, $project->slug));

        return 0;
    }
}
