<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure\Cli;

use Deployer\Runs\Application\CancelRun;
use Deployer\Shared\Infrastructure\Application;
use Deployer\Shared\Infrastructure\Cli\Command;
use Deployer\Shared\Infrastructure\Cli\Console;

final class CancelRunCommand implements Command
{
    public function __construct(private readonly Application $app)
    {
    }

    public function name(): string
    {
        return 'run:cancel';
    }

    public function description(): string
    {
        return 'Cancel run <id> (queued runs immediately, running runs cooperatively)';
    }

    public function run(array $args, Console $console): int
    {
        $id = $args[0] ?? '';
        if (!ctype_digit($id)) {
            $console->error('Missing or invalid <run> id.');

            return 1;
        }
        $result = ($this->app->cancelRun())((int) $id);
        $console->line(sprintf('Run #%d: %s', (int) $id, $result));

        return $result === CancelRun::NOT_FOUND ? 1 : 0;
    }
}
