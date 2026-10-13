<?php

declare(strict_types=1);

namespace Deployer\Notifiers\Application;

use Deployer\Notifiers\Application\Port\Notifier;
use Deployer\Notifiers\Domain\NotifierRepository;
use Deployer\Projects\Domain\ProjectRepository;
use Deployer\Runs\Application\Port\RunFinishedListener;
use Deployer\Runs\Domain\LogRepository;
use Deployer\Runs\Domain\LogStream;
use Deployer\Runs\Domain\RunRepository;
use Deployer\Runs\Domain\StepStatus;
use Deployer\Shared\Domain\Clock;
use Throwable;

/**
 * Sends the run-finished message through the project's notifier. Never throws:
 * a delivery problem is recorded as a system log line and the run status is untouched.
 */
final class NotifyRunFinished implements RunFinishedListener
{
    private const LOG_TAIL_LINES = 10;

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly NotifierRepository $notifiers,
        private readonly RunRepository $runs,
        private readonly LogRepository $logs,
        private readonly Notifier $notifier,
        private readonly Clock $clock,
    ) {
    }

    public function runFinished(int $runId): void
    {
        $channelName = null;
        try {
            $run = $this->runs->find($runId);
            $project = $run === null ? null : $this->projects->find($run->projectId);
            $channel = $project?->notifierId === null ? null : $this->notifiers->find($project->notifierId);
            if ($run === null || $project === null || $channel === null || !$run->status->isFinal()) {
                return;
            }
            $channelName = $channel->name;

            [$failedStep, $tail] = $this->failureContext($runId);
            $this->notifier->send($channel, RunFinishedMessage::render($project, $run, $failedStep, $tail));
            $this->system($runId, sprintf('Notification sent via "%s".', $channel->name));
        } catch (Throwable $e) {
            $this->system($runId, sprintf('Notification via "%s" failed: %s', $channelName ?? 'notifier', $e->getMessage()));
        }
    }

    /** @return array{?string, list<string>} failed step name and its last log lines */
    private function failureContext(int $runId): array
    {
        $failed = null;
        foreach ($this->runs->steps($runId) as $step) {
            // The step that stopped the run; continue_on_error failures did not.
            if ($step->status === StepStatus::Failed && !$step->continueOnError) {
                $failed = $step;
            }
        }
        if ($failed === null) {
            return [null, []];
        }

        $tail = $this->logs->stepOutputTail($runId, $failed->position, self::LOG_TAIL_LINES);

        return [$failed->name, array_map(static fn ($line): string => $line->line, $tail)];
    }

    private function system(int $runId, string $line): void
    {
        try {
            $this->logs->append($runId, null, LogStream::System, $line, $this->clock->now());
        } catch (Throwable) {
            // Logging must not break the runner either.
        }
    }
}
