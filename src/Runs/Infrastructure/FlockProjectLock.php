<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure;

use Deployer\Runs\Application\Port\ProjectLock;
use RuntimeException;

/**
 * Per-project advisory lock file. The kernel releases it when the holder dies,
 * so a crashed runner never leaves a stale lock behind.
 */
final class FlockProjectLock implements ProjectLock
{
    public function __construct(private readonly string $locksDir)
    {
    }

    public function tryAcquire(int $projectId): ?callable
    {
        if (!is_dir($this->locksDir) && !@mkdir($this->locksDir, 0700, true) && !is_dir($this->locksDir)) {
            throw new RuntimeException(sprintf('Cannot create locks directory %s', $this->locksDir));
        }
        $handle = fopen(sprintf('%s/project-%d.lock', $this->locksDir, $projectId), 'c+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open project lock file.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }
        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        return static function () use ($handle): void {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        };
    }
}
