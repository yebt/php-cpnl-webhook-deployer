<?php

declare(strict_types=1);

namespace Deployer\Runs\Application\Port;

use Deployer\Pipeline\Domain\Step;
use Deployer\Runs\Domain\LogStream;

interface StepExecutor
{
    /**
     * Runs a step's script in its own process group and blocks until it ends,
     * honouring the step's timeout and strict mode. Cancellation and timeout
     * terminate the whole process tree.
     *
     * The step's commands run in order in one shell; `$onCommand` is called with
     * the 0-based index of each command right before its output.
     *
     * @param callable(LogStream, string): void $onLine       called for each complete output line
     * @param callable(int): void               $onCommand    called when a command starts
     * @param callable(): bool                  $shouldCancel polled periodically
     */
    public function execute(Step $step, string $workingDir, callable $onLine, callable $onCommand, callable $shouldCancel): StepOutcome;
}
