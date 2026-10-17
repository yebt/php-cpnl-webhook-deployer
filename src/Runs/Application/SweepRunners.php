<?php

declare(strict_types=1);

namespace Deployer\Runs\Application;

use Deployer\Runs\Application\Port\ProcessProbe;
use Deployer\Runs\Application\Port\ProjectLock;
use Deployer\Runs\Application\Port\RunFinishedListener;
use Deployer\Runs\Application\Port\RunnerLauncher;
use Deployer\Runs\Domain\LogRepository;
use Deployer\Runs\Domain\LogStream;
use Deployer\Runs\Domain\RunRepository;
use Deployer\Runs\Domain\RunStatus;
use Deployer\Runs\Domain\StepStatus;
use Deployer\Shared\Domain\Clock;
use Throwable;

/**
 * Recovery for cron (`runner:sweep`): fails runs whose runner died and restarts
 * runners for projects that have queued runs but no lock holder. A run failed
 * here gets the same run-finished notification as one finished by its runner.
 */
final class SweepRunners
{
    public function __construct(
        private readonly RunRepository $runs,
        private readonly LogRepository $logs,
        private readonly ProjectLock $lock,
        private readonly RunnerLauncher $launcher,
        private readonly ProcessProbe $probe,
        private readonly Clock $clock,
        private readonly ?RunFinishedListener $onFinished = null,
    ) {
    }

    /** @return array{failedRuns: list<int>, launchedProjects: list<int>} */
    public function __invoke(): array
    {
        $failed = [];
        foreach ($this->runs->runningRuns() as $run) {
            if ($run->runnerPid !== null && $this->probe->isAlive($run->runnerPid)) {
                continue;
            }
            // A live runner always holds its project lock; if we can take it the
            // runner is gone even when the PID was recycled.
            $release = $this->lock->tryAcquire($run->projectId);
            if ($release === null) {
                continue;
            }
            try {
                $current = $this->runs->find($run->id);
                if ($current === null || $current->status !== RunStatus::Running) {
                    continue;
                }
                $now = $this->clock->now();
                $message = sprintf('Runner process %s is gone; marking run as failed.', $run->runnerPid ?? '(unknown)');
                $this->logs->append($run->id, null, LogStream::System, $message, $now);
                $this->runs->closeOpenSteps($run->id, StepStatus::Failed, $now);
                $this->runs->finish($run->id, RunStatus::Failed, $message, $now);
                $failed[] = $run->id;
            } finally {
                $release();
            }
            // Outside the lock: delivery may be slow and must not block a new runner.
            if (in_array($run->id, $failed, true)) {
                $this->notifyFinished($run->id);
            }
        }

        $launched = [];
        foreach ($this->runs->projectsWithQueuedRuns() as $projectId) {
            $release = $this->lock->tryAcquire($projectId);
            if ($release === null) {
                continue; // a runner is draining this project
            }
            $release();
            $this->launcher->launch($projectId);
            $launched[] = $projectId;
        }

        return ['failedRuns' => $failed, 'launchedProjects' => $launched];
    }

    /** After the final status is persisted, so a listener can never change it. */
    private function notifyFinished(int $runId): void
    {
        if ($this->onFinished === null) {
            return;
        }
        try {
            $this->onFinished->runFinished($runId);
        } catch (Throwable $e) {
            try {
                $this->logs->append($runId, null, LogStream::System, 'Run-finished hook failed: ' . $e->getMessage(), $this->clock->now());
            } catch (Throwable) {
                // The sweep must keep recovering other runs.
            }
        }
    }
}
