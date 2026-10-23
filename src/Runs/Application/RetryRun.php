<?php

declare(strict_types=1);

namespace Deployer\Runs\Application;

use Deployer\Runs\Domain\RunRepository;
use Deployer\Runs\Domain\RunStatus;

/**
 * Queues a new run of the same project for a run that failed or was cancelled.
 * Like a manual trigger, the new run reads the project's CURRENT instructions file
 * when it starts (not the failed run's snapshot), so a fixed pipeline is picked up.
 * The action of an action run is kept.
 */
final class RetryRun
{
    public const TRIGGER = 'retry';

    public function __construct(
        private readonly RunRepository $runs,
        private readonly EnqueueRun $enqueue,
    ) {
    }

    /**
     * @return int|null the new run id; null when the source run does not exist
     * @throws RunNotRetryable when the source run did not end failed or cancelled
     */
    public function __invoke(int $runId): ?int
    {
        $run = $this->runs->find($runId);
        if ($run === null) {
            return null;
        }
        if ($run->status !== RunStatus::Failed && $run->status !== RunStatus::Cancelled) {
            throw new RunNotRetryable(sprintf('Only failed or cancelled runs can be retried; run #%d is %s.', $run->id, $run->status->value));
        }

        // An action run is retried as the same action; the runner fails it clearly
        // if the action no longer exists in the current file.
        return ($this->enqueue)($run->projectId, self::TRIGGER, retryOf: $run->id, action: $run->action, actionName: $run->actionName);
    }
}
