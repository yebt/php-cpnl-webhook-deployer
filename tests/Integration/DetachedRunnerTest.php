<?php

declare(strict_types=1);

namespace Deployer\Tests\Integration;

use Deployer\Runs\Application\CancelRun;
use Deployer\Runs\Domain\Run;
use Deployer\Runs\Domain\RunStatus;
use Deployer\Runs\Domain\StepStatus;
use Deployer\Runs\Infrastructure\PosixProcessProbe;
use Deployer\Tests\Support\RunsTestCase;

/**
 * End-to-end through real detached runners: `php bin/deployer runner <id>` is
 * spawned with setsid/nohup exactly as in production.
 */
final class DetachedRunnerTest extends RunsTestCase
{
    private function waitForFinal(int $runId, float $timeout = 20.0): Run
    {
        $deadline = microtime(true) + $timeout;
        do {
            $run = $this->app->runs()->find($runId);
            if ($run->status->isFinal()) {
                return $run;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        self::fail(sprintf("Run %d did not finish; status %s. Log:\n%s", $runId, $run->status->value, implode("\n", $this->logLines($runId))));
    }

    private function waitForFile(string $file, float $timeout = 10.0): string
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (is_file($file) && ($contents = trim((string) file_get_contents($file))) !== '') {
                return $contents;
            }
            usleep(50_000);
        }
        self::fail('Timed out waiting for ' . $file);
    }

    public function testRunsOfTheSameProjectAreSequential(): void
    {
        $project = $this->createProject('seq', "steps:\n  - {name: Sleep, run: sleep 1}\n");
        $enqueue = $this->app->enqueueRun();

        $first = $enqueue($project->id, 'test');
        $second = $enqueue($project->id, 'test');

        $a = $this->waitForFinal($first);
        $b = $this->waitForFinal($second);
        self::assertSame(RunStatus::Success, $a->status);
        self::assertSame(RunStatus::Success, $b->status);
        self::assertGreaterThanOrEqual($a->finishedAt, $b->startedAt, 'second run must start after the first finished');
    }

    public function testRunsOfDifferentProjectsRunInParallel(): void
    {
        $alpha = $this->createProject('alpha', "steps:\n  - {name: Sleep, run: sleep 2}\n");
        $beta = $this->createProject('beta', "steps:\n  - {name: Sleep, run: sleep 2}\n");
        $enqueue = $this->app->enqueueRun();

        $started = microtime(true);
        $a = $enqueue($alpha->id, 'test');
        $b = $enqueue($beta->id, 'test');
        $runA = $this->waitForFinal($a);
        $runB = $this->waitForFinal($b);

        self::assertSame(RunStatus::Success, $runA->status);
        self::assertSame(RunStatus::Success, $runB->status);
        self::assertLessThan($runA->finishedAt, $runB->startedAt, 'B started before A finished');
        self::assertLessThan($runB->finishedAt, $runA->startedAt, 'A started before B finished');
        self::assertLessThan(3.8, microtime(true) - $started, 'two 2s runs should overlap');
        self::assertNotSame($runA->runnerPid, $runB->runnerPid);
    }

    public function testCancellingARunningRunKillsItsProcessTree(): void
    {
        $pidFile = $this->makeTempDir() . '/sleep.pid';
        $project = $this->createProject('cancel', sprintf(
            "steps:\n  - name: Long\n    run: sleep 300 & echo \$! > %s; wait\n  - name: Never\n    run: echo never\n",
            $pidFile,
        ));

        $runId = ($this->app->enqueueRun())($project->id, 'test');
        $sleepPid = (int) $this->waitForFile($pidFile);
        self::assertSame(CancelRun::REQUESTED, ($this->app->cancelRun())($runId));
        $run = $this->waitForFinal($runId);

        self::assertSame(RunStatus::Cancelled, $run->status);
        $steps = $this->app->runs()->steps($runId);
        self::assertSame([StepStatus::Cancelled, StepStatus::Skipped], [$steps[0]->status, $steps[1]->status]);
        usleep(200_000);
        self::assertFalse((new PosixProcessProbe())->isAlive($sleepPid), 'grandchild sleep must be gone');
        self::assertContains('system: Run cancelled by request.', $this->logLines($runId));
    }

    public function testSweepRestartsARunnerForAnOrphanedQueue(): void
    {
        $project = $this->createProject('orphan', "steps:\n  - {name: Hello, run: echo hello}\n");
        // Enqueue without launching, as if the spawning request had died.
        $runId = ($this->app->enqueueRun($this->recordingLauncher()))($project->id, 'test');

        $report = ($this->app->sweepRunners())();

        self::assertSame([$project->id], $report['launchedProjects']);
        self::assertSame(RunStatus::Success, $this->waitForFinal($runId)->status);
        self::assertContains('stdout: hello', $this->logLines($runId));
    }

    public function testRunnerCommandDrainsQueueThroughTheCli(): void
    {
        $project = $this->createProject('cli', "steps:\n  - {name: Hello, run: echo from-cli}\n");
        $runId = ($this->app->enqueueRun($this->recordingLauncher()))($project->id, 'test');

        $cmd = sprintf(
            'DEPLOYER_DATA_DIR=%s %s %s runner cli 2>&1',
            escapeshellarg($this->dataDir),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 2) . '/bin/deployer'),
        );
        exec($cmd, $output, $code);

        self::assertSame(0, $code, implode("\n", $output));
        self::assertStringContainsString('executed 1 run(s)', implode("\n", $output));
        self::assertSame(RunStatus::Success, $this->app->runs()->find($runId)->status);
        self::assertContains('stdout: from-cli', $this->logLines($runId));
    }
}
