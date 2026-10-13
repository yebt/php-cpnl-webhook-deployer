<?php

declare(strict_types=1);

namespace Deployer\Tests\Runs;

use DateTimeImmutable;
use Deployer\Runs\Application\CancelRun;
use Deployer\Runs\Application\Port\ProcessProbe;
use Deployer\Runs\Application\SweepRunners;
use Deployer\Runs\Domain\LogStream;
use Deployer\Runs\Domain\RunStatus;
use Deployer\Runs\Domain\StepStatus;
use Deployer\Runs\Infrastructure\FlockProjectLock;
use Deployer\Shared\Infrastructure\SystemClock;
use Deployer\Tests\Support\RunsTestCase;
use InvalidArgumentException;

final class RunQueueTest extends RunsTestCase
{
    private const YAML = "steps:\n  - {name: A, run: echo a}\n";

    public function testEnqueueCreatesQueuedRunAndLaunchesRunner(): void
    {
        $project = $this->createProject('shop', self::YAML);
        $launcher = $this->recordingLauncher();

        $runId = ($this->app->enqueueRun($launcher))($project->id, 'webhook');

        $run = $this->app->runs()->find($runId);
        self::assertSame(RunStatus::Queued, $run->status);
        self::assertSame('webhook', $run->trigger);
        self::assertSame([$project->id], $launcher->launched);
    }

    public function testEnqueueRejectsUnknownProject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ($this->app->enqueueRun($this->recordingLauncher()))(999, 'test');
    }

    public function testCancelOutcomes(): void
    {
        $project = $this->createProject('shop', self::YAML);
        $enqueue = $this->app->enqueueRun($this->recordingLauncher());
        $cancel = $this->app->cancelRun();

        $queued = $enqueue($project->id, 'test');
        self::assertSame(CancelRun::CANCELLED, $cancel($queued));
        self::assertSame(RunStatus::Cancelled, $this->app->runs()->find($queued)->status);
        self::assertSame(CancelRun::ALREADY_FINISHED, $cancel($queued));

        $running = $enqueue($project->id, 'test');
        $this->app->runs()->claimNextQueued($project->id, 1234, new DateTimeImmutable());
        self::assertSame(CancelRun::REQUESTED, $cancel($running));
        self::assertTrue($this->app->runs()->isCancelRequested($running));
        self::assertSame(RunStatus::Running, $this->app->runs()->find($running)->status, 'the runner records the final status');

        self::assertSame(CancelRun::NOT_FOUND, $cancel(424242));
    }

    public function testIncrementalLogReadsByCursor(): void
    {
        $project = $this->createProject('shop', self::YAML);
        $runId = ($this->app->enqueueRun($this->recordingLauncher()))($project->id, 'test');
        $logs = $this->app->logs();
        foreach (['one', 'two', 'three'] as $line) {
            $logs->append($runId, 0, LogStream::Stdout, $line, new DateTimeImmutable());
        }
        $read = $this->app->readRunLogs();

        $first = $read($runId, 0, 2);
        self::assertSame(['one', 'two'], array_map(static fn ($l) => $l->line, $first->lines));
        self::assertFalse($first->isComplete(2));

        $second = $read($runId, $first->nextAfterId, 2);
        self::assertSame(['three'], array_map(static fn ($l) => $l->line, $second->lines));

        $empty = $read($runId, $second->nextAfterId, 2);
        self::assertSame([], $empty->lines);
        self::assertSame($second->nextAfterId, $empty->nextAfterId);
        self::assertSame(RunStatus::Queued, $empty->runStatus);
    }

    public function testLogLinesAreStoredAsValidUtf8(): void
    {
        $project = $this->createProject('shop', self::YAML);
        $runId = ($this->app->enqueueRun($this->recordingLauncher()))($project->id, 'test');
        $this->app->logs()->append($runId, null, LogStream::Stdout, "bad \xC3\x28 byte", new DateTimeImmutable());

        $line = $this->app->logs()->since($runId, 0, 1)[0]->line;
        self::assertSame(1, preg_match('//u', $line));
    }

    public function testSweepFailsRunsWithDeadRunnerAndRelaunchesOrphanedQueues(): void
    {
        $dead = $this->createProject('dead', self::YAML);
        $alive = $this->createProject('alive', self::YAML);
        $orphan = $this->createProject('orphan', self::YAML);
        $busy = $this->createProject('busy', self::YAML);
        $enqueue = $this->app->enqueueRun($this->recordingLauncher());
        $runs = $this->app->runs();

        $deadRun = $enqueue($dead->id, 'test');
        $runs->claimNextQueued($dead->id, 111, new DateTimeImmutable());
        $runs->createSteps($deadRun, [new \Deployer\Pipeline\Domain\Step('A', 'echo', 10, false), new \Deployer\Pipeline\Domain\Step('B', 'echo', 10, false)]);
        $runs->startStep($deadRun, 0, new DateTimeImmutable());
        $aliveRun = $enqueue($alive->id, 'test');
        $runs->claimNextQueued($alive->id, 222, new DateTimeImmutable());
        $enqueue($orphan->id, 'test');
        $enqueue($busy->id, 'test');

        $lock = new FlockProjectLock($this->app->config()->locksDir());
        $releaseBusy = $lock->tryAcquire($busy->id);
        $launcher = $this->recordingLauncher();
        $probe = new class () implements ProcessProbe {
            public function isAlive(int $pid): bool
            {
                return $pid === 222;
            }
        };
        $sweep = new SweepRunners($runs, $this->app->logs(), $lock, $launcher, $probe, new SystemClock());

        $report = $sweep();
        $releaseBusy();

        self::assertSame([$deadRun], $report['failedRuns']);
        self::assertSame(RunStatus::Failed, $runs->find($deadRun)->status);
        self::assertSame([StepStatus::Failed, StepStatus::Skipped], array_map(static fn ($s) => $s->status, $runs->steps($deadRun)));
        self::assertSame(RunStatus::Running, $runs->find($aliveRun)->status);
        self::assertSame([$orphan->id], $report['launchedProjects']);
        self::assertSame([$orphan->id], $launcher->launched);
    }

    public function testProjectLockIsExclusiveUntilReleased(): void
    {
        $lock = new FlockProjectLock($this->app->config()->locksDir());

        $release = $lock->tryAcquire(1);
        self::assertNotNull($release);
        self::assertNull($lock->tryAcquire(1), 'second holder must be rejected');
        self::assertNotNull($other = $lock->tryAcquire(2), 'other projects are independent');
        $other();
        $release();
        $again = $lock->tryAcquire(1);
        self::assertNotNull($again);
        $again();
    }
}
