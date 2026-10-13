<?php

declare(strict_types=1);

namespace Deployer\Runs\Application;

use Deployer\Projects\Domain\ProjectRepository;
use Deployer\Runs\Application\Port\RunnerLauncher;
use Deployer\Runs\Domain\RunRepository;
use Deployer\Shared\Domain\Clock;
use InvalidArgumentException;

final class EnqueueRun
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly RunRepository $runs,
        private readonly RunnerLauncher $launcher,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string,string>|null $triggerMeta informational summary stored with the run
     * @return int the new run id
     */
    public function __invoke(int $projectId, string $trigger, ?array $triggerMeta = null): int
    {
        if ($this->projects->find($projectId) === null) {
            throw new InvalidArgumentException(sprintf('Project %d not found.', $projectId));
        }

        $runId = $this->runs->enqueue($projectId, $trigger, $this->clock->now(), $triggerMeta);
        // If a runner already drains this project it keeps the lock and the new
        // runner exits immediately; the draining runner picks the run up.
        $this->launcher->launch($projectId);

        return $runId;
    }
}
