<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure;

use Deployer\Runs\Application\Port\RunnerLauncher;
use Deployer\Runs\Infrastructure\Process\Shell;
use RuntimeException;

/**
 * Starts `php <entry> runner <project>` fully detached (new session, no inherited
 * descriptors, output to /dev/null) and returns immediately, so it survives the
 * end of the PHP-FPM request that spawned it.
 */
final class DetachedRunnerLauncher implements RunnerLauncher
{
    public function __construct(
        private readonly string $phpCli,
        private readonly string $entryScript,
        private readonly string $dataDir,
    ) {
    }

    public function launch(int $projectId): void
    {
        $setsid = Shell::setsid();
        $runner = sprintf(
            '%snohup %s %s runner %d </dev/null >/dev/null 2>&1 &',
            $setsid !== '' ? escapeshellarg($setsid) . ' ' : '',
            escapeshellarg($this->phpCli),
            escapeshellarg($this->entryScript),
            $projectId,
        );
        $env = Shell::environment([
            'DEPLOYER_DATA_DIR' => $this->dataDir,
            'DEPLOYER_PHP_CLI' => $this->phpCli,
        ]);

        $process = proc_open(
            ['bash', '-c', Shell::CLOSE_INHERITED_FDS . '; ' . $runner],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the runner process (is proc_open allowed?).');
        }
        // bash backgrounds the runner and exits right away.
        proc_close($process);
    }
}
