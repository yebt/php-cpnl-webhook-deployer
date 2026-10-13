<?php

declare(strict_types=1);

namespace Deployer\Tests\Runs;

use Deployer\Runs\Domain\RunStatus;
use Deployer\Runs\Domain\StepStatus;
use Deployer\Tests\Support\RunsTestCase;

final class ExecuteRunTest extends RunsTestCase
{
    public function testSuccessfulRunPersistsStepsCommandsAndTaggedOutput(): void
    {
        $project = $this->createProject('shop', <<<'YAML'
            vars:
              GREETING: hello
            steps:
              - name: Greet
                run: echo "${GREETING} ${PROJECT} run ${RUN_ID}"; echo oops >&2
              - name: Where
                run: pwd
            YAML);

        $runId = $this->runInProcess($project);

        $run = $this->app->runs()->find($runId);
        self::assertSame(RunStatus::Success, $run->status);
        self::assertNotNull($run->startedAt);
        self::assertNotNull($run->finishedAt);
        self::assertStringContainsString('${GREETING}', (string) $run->pipelineYaml, 'pipeline is snapshotted verbatim');

        $steps = $this->app->runs()->steps($runId);
        self::assertSame([StepStatus::Success, StepStatus::Success], [$steps[0]->status, $steps[1]->status]);
        self::assertSame(0, $steps[0]->exitCode);
        self::assertSame(sprintf('echo "hello shop run %d"; echo oops >&2', $runId), $steps[0]->command);

        $lines = $this->logLines($runId);
        self::assertContains('system: Step 1/2: Greet', $lines);
        self::assertContains(sprintf('system: $ echo "hello shop run %d"; echo oops >&2', $runId), $lines);
        self::assertContains(sprintf('stdout: hello shop run %d', $runId), $lines);
        self::assertContains('stderr: oops', $lines);
        self::assertContains('stdout: ' . $project->folder, $lines, 'steps run in the project folder');
        self::assertSame('system: Run finished: success', end($lines));

        foreach ($this->app->logs()->since($runId, 0, 100) as $line) {
            self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{3}$/', $line->loggedAt);
        }
    }

    public function testFailingStepStopsPipelineAndSkipsTheRest(): void
    {
        $project = $this->createProject('api', <<<'YAML'
            steps:
              - name: One
                run: echo one
              - name: Two
                run: exit 3
              - name: Three
                run: echo three
            YAML);

        $runId = $this->runInProcess($project);

        $run = $this->app->runs()->find($runId);
        self::assertSame(RunStatus::Failed, $run->status);
        self::assertStringContainsString('Step "Two" failed', (string) $run->error);
        $steps = $this->app->runs()->steps($runId);
        self::assertSame(StepStatus::Failed, $steps[1]->status);
        self::assertSame(3, $steps[1]->exitCode);
        self::assertSame(StepStatus::Skipped, $steps[2]->status);
        self::assertNotContains('stdout: three', $this->logLines($runId));
    }

    public function testContinueOnErrorKeepsGoingAndRunSucceeds(): void
    {
        $project = $this->createProject('web', <<<'YAML'
            steps:
              - name: Optional
                run: exit 1
                continue_on_error: true
              - name: After
                run: echo after
            YAML);

        $runId = $this->runInProcess($project);

        self::assertSame(RunStatus::Success, $this->app->runs()->find($runId)->status);
        $steps = $this->app->runs()->steps($runId);
        self::assertSame(StepStatus::Failed, $steps[0]->status);
        self::assertSame(StepStatus::Success, $steps[1]->status);
        self::assertContains('stdout: after', $this->logLines($runId));
    }

    public function testStepTimeoutKillsTheStepAndFailsTheRun(): void
    {
        $project = $this->createProject('slow', <<<'YAML'
            steps:
              - name: Hang
                run: echo start; sleep 30
                timeout: 1
            YAML);

        $started = microtime(true);
        $runId = $this->runInProcess($project);

        self::assertLessThan(10, microtime(true) - $started);
        self::assertSame(RunStatus::Failed, $this->app->runs()->find($runId)->status);
        self::assertSame(StepStatus::Failed, $this->app->runs()->steps($runId)[0]->status);
        self::assertContains('system: Step timed out after 1s; process group terminated.', $this->logLines($runId));
    }

    public function testInvalidPipelineFailsWithValidationErrorsInTheLog(): void
    {
        $project = $this->createProject('bad', "steps:\n  - name: X\n    run: echo \${NOPE}\n");

        $runId = $this->runInProcess($project);

        self::assertSame(RunStatus::Failed, $this->app->runs()->find($runId)->status);
        self::assertSame([], $this->app->runs()->steps($runId));
        self::assertContains('system: Pipeline error: steps[0] (X): unknown variable "NOPE".', $this->logLines($runId));
    }

    public function testMissingInstructionsFileFailsTheRun(): void
    {
        $project = $this->createProject('missing', 'steps: []');
        unlink($project->instructionsPath());

        $runId = $this->runInProcess($project);

        $run = $this->app->runs()->find($runId);
        self::assertSame(RunStatus::Failed, $run->status);
        self::assertStringContainsString('Instructions file not found', (string) $run->error);
    }

    public function testCancelRequestedBeforeAStepStartsCancelsTheRun(): void
    {
        $project = $this->createProject('cancel', "steps:\n  - {name: A, run: echo a}\n");
        $runId = ($this->app->enqueueRun($this->recordingLauncher()))($project->id, 'test');
        $run = $this->app->runs()->claimNextQueued($project->id, getmypid(), new \DateTimeImmutable());
        $this->app->runs()->requestCancel($runId);

        $status = ($this->app->executeRun())($run);

        self::assertSame(RunStatus::Cancelled, $status);
        self::assertSame(RunStatus::Cancelled, $this->app->runs()->find($runId)->status);
        self::assertSame(StepStatus::Skipped, $this->app->runs()->steps($runId)[0]->status);
    }

    public function testStepsDoNotInheritTheProjectLockDescriptor(): void
    {
        $project = $this->createProject('fds', "steps:\n  - {name: Fds, run: 'ls -l /proc/\$\$/fd'}\n");

        $runId = $this->runInProcess($project);

        self::assertSame(RunStatus::Success, $this->app->runs()->find($runId)->status);
        $output = implode("\n", $this->logLines($runId));
        self::assertStringNotContainsString('.lock', $output);
        self::assertStringNotContainsString('deployer.sqlite', $output);
    }

    public function testRunsOfAProjectAreDrainedInFifoOrder(): void
    {
        $project = $this->createProject('fifo', "steps:\n  - {name: A, run: 'echo \${RUN_ID}'}\n");
        $enqueue = $this->app->enqueueRun($this->recordingLauncher());
        $first = $enqueue($project->id, 'test');
        $second = $enqueue($project->id, 'test');

        self::assertSame(2, ($this->app->drainProjectQueue())($project->id));

        $a = $this->app->runs()->find($first);
        $b = $this->app->runs()->find($second);
        self::assertSame(RunStatus::Success, $a->status);
        self::assertSame(RunStatus::Success, $b->status);
        self::assertGreaterThanOrEqual($a->finishedAt, $b->startedAt);
    }
}
