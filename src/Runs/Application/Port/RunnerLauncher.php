<?php

declare(strict_types=1);

namespace Deployer\Runs\Application\Port;

/** Starts a detached runner process for a project and returns immediately. */
interface RunnerLauncher
{
    public function launch(int $projectId): void;
}
