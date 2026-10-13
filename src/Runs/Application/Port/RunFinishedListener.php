<?php

declare(strict_types=1);

namespace Deployer\Runs\Application\Port;

/** Called by the runner after a run reached its final status (which is already persisted). */
interface RunFinishedListener
{
    public function runFinished(int $runId): void;
}
