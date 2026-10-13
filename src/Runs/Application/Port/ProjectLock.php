<?php

declare(strict_types=1);

namespace Deployer\Runs\Application\Port;

interface ProjectLock
{
    /**
     * Non-blocking. Returns a release callback when the lock was acquired, or null
     * when another runner already holds it.
     *
     * @return (callable(): void)|null
     */
    public function tryAcquire(int $projectId): ?callable;
}
