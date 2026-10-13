<?php

declare(strict_types=1);

namespace Deployer\Runs\Application;

use Deployer\Runs\Domain\LogRepository;
use Deployer\Runs\Domain\LogStream;
use Deployer\Runs\Domain\RunRepository;
use Deployer\Runs\Domain\RunStatus;
use Deployer\Shared\Domain\Clock;

final class CancelRun
{
    public const NOT_FOUND = 'not_found';
    public const ALREADY_FINISHED = 'already_finished';
    /** The run was queued and is now cancelled. */
    public const CANCELLED = 'cancelled';
    /** The run is running; its runner will stop it and record `cancelled`. */
    public const REQUESTED = 'requested';

    public function __construct(
        private readonly RunRepository $runs,
        private readonly LogRepository $logs,
        private readonly Clock $clock,
    ) {
    }

    public function __invoke(int $runId): string
    {
        $run = $this->runs->find($runId);
        if ($run === null) {
            return self::NOT_FOUND;
        }

        if ($run->status === RunStatus::Queued && $this->runs->cancelQueued($runId, $this->clock->now())) {
            $this->logs->append($runId, null, LogStream::System, 'Run cancelled before it started.', $this->clock->now());

            return self::CANCELLED;
        }

        if ($this->runs->requestCancel($runId)) {
            return self::REQUESTED;
        }

        // Status changed between the read and the update.
        $current = $this->runs->find($runId);

        return $current !== null && $current->status === RunStatus::Queued ? $this->__invoke($runId) : self::ALREADY_FINISHED;
    }
}
