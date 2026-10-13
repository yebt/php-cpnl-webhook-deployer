<?php

declare(strict_types=1);

namespace Deployer\Runs\Application;

use Deployer\Runs\Application\Port\ProjectLock;
use Deployer\Runs\Domain\RunRepository;
use Deployer\Shared\Domain\Clock;

/**
 * Body of the `runner <project>` process: holds the project lock and executes
 * queued runs FIFO until the queue is empty, then exits.
 */
final class DrainProjectQueue
{
    public function __construct(
        private readonly ProjectLock $lock,
        private readonly RunRepository $runs,
        private readonly ExecuteRun $executeRun,
        private readonly Clock $clock,
        private readonly int $runnerPid,
    ) {
    }

    /** @return int number of runs executed; 0 also when another runner holds the lock */
    public function __invoke(int $projectId): int
    {
        $executed = 0;
        do {
            $release = $this->lock->tryAcquire($projectId);
            if ($release === null) {
                return $executed;
            }
            try {
                while (($run = $this->runs->claimNextQueued($projectId, $this->runnerPid, $this->clock->now())) !== null) {
                    ($this->executeRun)($run);
                    ++$executed;
                }
            } finally {
                $release();
            }
            // A run enqueued after the last claim may have had its runner rejected by
            // our lock; re-check after releasing so it is never left orphaned.
        } while ($this->runs->hasQueued($projectId));

        return $executed;
    }
}
