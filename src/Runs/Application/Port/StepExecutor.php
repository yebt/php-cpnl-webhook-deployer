<?php

declare(strict_types=1);

namespace Deployer\Runs\Application\Port;

use Deployer\Runs\Domain\LogStream;

interface StepExecutor
{
    /**
     * Runs a shell command in its own process group and blocks until it ends.
     * Cancellation and timeout terminate the whole process tree.
     *
     * @param callable(LogStream, string): void $onLine       called for each complete output line
     * @param callable(): bool                  $shouldCancel polled periodically
     */
    public function execute(string $command, string $workingDir, int $timeoutSeconds, callable $onLine, callable $shouldCancel): StepOutcome;
}
