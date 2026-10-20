<?php

declare(strict_types=1);

namespace Deployer\Runs\Domain;

use DateTimeImmutable;
use Deployer\Pipeline\Domain\Step;

interface RunRepository
{
    /** @param array<string,string>|null $triggerMeta */
    public function enqueue(int $projectId, string $trigger, DateTimeImmutable $now, ?array $triggerMeta = null, ?int $retryOf = null): int;

    /** @return list<Run> newest first */
    public function search(RunFilter $filter): array;

    /** True when the project has queued or running runs. */
    public function hasActive(int $projectId): bool;

    public function find(int $runId): ?Run;

    /**
     * Atomically moves the oldest queued run of the project to `running`.
     * Returns null when the project's queue is empty.
     */
    public function claimNextQueued(int $projectId, int $runnerPid, DateTimeImmutable $now): ?Run;

    public function hasQueued(int $projectId): bool;

    /** @return list<int> project ids that have at least one queued run */
    public function projectsWithQueuedRuns(): array;

    /** @return list<Run> */
    public function runningRuns(): array;

    public function snapshotPipeline(int $runId, string $yaml): void;

    /** @param list<Step> $steps */
    public function createSteps(int $runId, array $steps): void;

    /** @return list<RunStepRecord> */
    public function steps(int $runId): array;

    public function startStep(int $runId, int $position, DateTimeImmutable $now): void;

    public function finishStep(int $runId, int $position, StepStatus $status, ?int $exitCode, DateTimeImmutable $now): void;

    /** Marks pending steps as skipped and steps still running with $runningAs. */
    public function closeOpenSteps(int $runId, StepStatus $runningAs, DateTimeImmutable $now): void;

    /** Moves a running run to a final status. No-op if the run is not running. */
    public function finish(int $runId, RunStatus $status, ?string $error, DateTimeImmutable $now): void;

    /** Cancels a queued run directly. Returns false if it was not queued anymore. */
    public function cancelQueued(int $runId, DateTimeImmutable $now): bool;

    /** Flags a running run for cooperative cancellation. Returns false if it was not running. */
    public function requestCancel(int $runId): bool;

    public function isCancelRequested(int $runId): bool;
}
