<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure\Cli;

use Deployer\Shared\Infrastructure\Application;
use Deployer\Shared\Infrastructure\Cli\Command;
use Deployer\Shared\Infrastructure\Cli\Console;
use InvalidArgumentException;

final class TriggerRunCommand implements Command
{
    private const ACTION_OPTION = '--action=';

    public function __construct(private readonly Application $app)
    {
    }

    public function name(): string
    {
        return 'run:trigger';
    }

    public function description(): string
    {
        return 'Queue a run for <project> (id or slug) and start its runner; --action=<id> runs an action';
    }

    public function run(array $args, Console $console): int
    {
        $action = null;
        $positional = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, self::ACTION_OPTION)) {
                $action = substr($arg, strlen(self::ACTION_OPTION));
                if ($action === '') {
                    throw new InvalidArgumentException('--action needs an action id, e.g. --action=clear-cache.');
                }
            } elseif (str_starts_with($arg, '--')) {
                throw new InvalidArgumentException(sprintf('Unknown option "%s"; usage: run:trigger <project> [--action=<id>].', $arg));
            } else {
                $positional[] = $arg;
            }
        }

        $project = ProjectArgument::resolve($this->app->projects(), $positional[0] ?? null);
        if ($action === null) {
            $runId = ($this->app->enqueueRun())($project->id, 'cli');
            $console->line(sprintf('Queued run #%d for project "%s".', $runId, $project->slug));

            return 0;
        }

        // UnknownAction / InstructionsUnavailable messages are printed by the CLI as errors.
        $runId = $this->app->projectActions()->enqueue($project, $action, 'cli');
        $console->line(sprintf('Queued run #%d of action "%s" for project "%s".', $runId, $action, $project->slug));

        return 0;
    }
}
