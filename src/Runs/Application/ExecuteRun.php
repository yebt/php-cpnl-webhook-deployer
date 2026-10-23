<?php

declare(strict_types=1);

namespace Deployer\Runs\Application;

use Deployer\Pipeline\Application\PipelineParser;
use Deployer\Pipeline\Domain\BuiltinVars;
use Deployer\Pipeline\Domain\PipelineValidationError;
use Deployer\Projects\Domain\InstructionsStore;
use Deployer\Projects\Domain\ProjectRepository;
use Deployer\Runs\Application\Port\RunFinishedListener;
use Deployer\Runs\Application\Port\StepExecutor;
use Deployer\Runs\Application\Port\StepOutcome;
use Deployer\Runs\Domain\LogRepository;
use Deployer\Runs\Domain\LogStream;
use Deployer\Runs\Domain\Run;
use Deployer\Runs\Domain\RunRepository;
use Deployer\Runs\Domain\RunStatus;
use Deployer\Runs\Domain\StepStatus;
use Deployer\Shared\Domain\Clock;
use RuntimeException;
use Throwable;

/**
 * Executes one claimed (running) run to a final status. The status is always
 * recorded explicitly here; exit codes are never used to infer cancellation.
 */
final class ExecuteRun
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly InstructionsStore $instructions,
        private readonly PipelineParser $parser,
        private readonly RunRepository $runs,
        private readonly LogRepository $logs,
        private readonly StepExecutor $executor,
        private readonly Clock $clock,
        private readonly ?RunFinishedListener $onFinished = null,
    ) {
    }

    public function __invoke(Run $run): RunStatus
    {
        try {
            [$status, $error] = $this->execute($run);
        } catch (Throwable $e) {
            [$status, $error] = [RunStatus::Failed, 'Internal error: ' . $e->getMessage()];
            $this->system($run->id, null, $error);
            $this->runs->closeOpenSteps($run->id, StepStatus::Failed, $this->clock->now());
        }

        $this->system($run->id, null, sprintf('Run finished: %s', $status->value));
        $this->runs->finish($run->id, $status, $error, $this->clock->now());

        // After the final status is persisted, so a listener can never change it.
        if ($this->onFinished !== null) {
            try {
                $this->onFinished->runFinished($run->id);
            } catch (Throwable $e) {
                $this->system($run->id, null, 'Run-finished hook failed: ' . $e->getMessage());
            }
        }

        return $status;
    }

    /** @return array{RunStatus, ?string} */
    private function execute(Run $run): array
    {
        $project = $this->projects->find($run->projectId)
            ?? throw new RuntimeException(sprintf('Project %d no longer exists.', $run->projectId));
        $this->system($run->id, null, sprintf(
            'Run #%d started for project "%s" (trigger: %s%s).',
            $run->id,
            $project->slug,
            $run->trigger,
            $run->action === null ? '' : ', action: ' . $run->action,
        ));

        try {
            $yaml = $this->instructions->read($project);
        } catch (RuntimeException $e) {
            return $this->failBeforeSteps($run->id, $e->getMessage());
        }
        $this->runs->snapshotPipeline($run->id, $yaml);

        try {
            $pipeline = $this->parser->parse($yaml, new BuiltinVars($project->slug, $run->id, $project->folder));
        } catch (PipelineValidationError $e) {
            foreach ($e->errors() as $message) {
                $this->system($run->id, null, 'Pipeline error: ' . $message);
            }

            return [RunStatus::Failed, $e->getMessage()];
        }

        $steps = $pipeline->steps;
        if ($run->action !== null) {
            $action = $pipeline->action($run->action);
            if ($action === null) {
                return $this->failBeforeSteps($run->id, sprintf(
                    'Action "%s" is not defined in the instructions file (it was removed or renamed after the run was queued).',
                    $run->action,
                ));
            }
            $steps = $action->steps;
        }

        if (!is_dir($project->folder)) {
            return $this->failBeforeSteps($run->id, sprintf('Project folder does not exist: %s', $project->folder));
        }

        $this->runs->createSteps($run->id, $steps);
        $total = count($steps);
        $failure = null;

        foreach ($steps as $position => $step) {
            if ($this->runs->isCancelRequested($run->id)) {
                return $this->cancel($run->id);
            }

            $this->runs->startStep($run->id, $position, $this->clock->now());
            $this->system($run->id, $position, sprintf('Step %d/%d: %s', $position + 1, $total, $step->name));
            if (!$step->strict) {
                // Only the non-default mode is logged, to keep ordinary logs clean.
                $this->system($run->id, $position, 'strict: false; only the exit code of the last command counts.');
            }

            $outcome = $this->executor->execute(
                $step,
                $project->folder,
                fn (LogStream $stream, string $line) => $this->logs->append($run->id, $position, $stream, $line, $this->clock->now()),
                fn (int $index) => $this->system($run->id, $position, '$ ' . ($step->commands[$index] ?? '')),
                fn (): bool => $this->runs->isCancelRequested($run->id),
            );
            $duration = sprintf('%.2fs', $outcome->durationSeconds);
            $where = count($step->commands) > 1
                ? sprintf(' at command %d/%d: %s', $outcome->command + 1, count($step->commands), $this->firstLine($step->commands[$outcome->command] ?? ''))
                : '';

            switch ($outcome->result) {
                case StepOutcome::SUCCEEDED:
                    $this->system($run->id, $position, sprintf('Step succeeded (exit code 0, %s).', $duration));
                    $this->runs->finishStep($run->id, $position, StepStatus::Success, 0, $this->clock->now());
                    break;

                case StepOutcome::CANCELLED:
                    $this->system($run->id, $position, sprintf('Step cancelled after %s; process group terminated.', $duration));
                    $this->runs->finishStep($run->id, $position, StepStatus::Cancelled, null, $this->clock->now());

                    return $this->cancel($run->id);

                case StepOutcome::TIMED_OUT:
                case StepOutcome::FAILED:
                    $message = $outcome->result === StepOutcome::TIMED_OUT
                        ? sprintf('Step timed out after %ds%s; process group terminated.', $step->timeout, $where)
                        : sprintf('Step failed (exit code %d, %s)%s', (int) $outcome->exitCode, $duration, $where === '' ? '.' : $where);
                    $this->system($run->id, $position, $message);
                    $this->runs->finishStep($run->id, $position, StepStatus::Failed, $outcome->exitCode, $this->clock->now());

                    if ($step->continueOnError) {
                        $this->system($run->id, $position, 'continue_on_error is set; continuing with the next step.');
                        break;
                    }
                    $failure = sprintf('Step "%s" failed: %s', $step->name, $message);
                    break 2;
            }
        }

        if ($failure !== null) {
            $this->runs->closeOpenSteps($run->id, StepStatus::Skipped, $this->clock->now());

            return [RunStatus::Failed, $failure];
        }

        return [RunStatus::Success, null];
    }

    /** @return array{RunStatus, string} */
    private function failBeforeSteps(int $runId, string $error): array
    {
        $this->system($runId, null, $error);

        return [RunStatus::Failed, $error];
    }

    /** @return array{RunStatus, ?string} */
    private function cancel(int $runId): array
    {
        $this->runs->closeOpenSteps($runId, StepStatus::Skipped, $this->clock->now());
        $this->system($runId, null, 'Run cancelled by request.');

        return [RunStatus::Cancelled, null];
    }

    private function firstLine(string $command): string
    {
        $line = trim(strtok(trim($command), "\n") ?: '');

        return mb_strlen($line) > 80 ? mb_substr($line, 0, 79) . '…' : $line;
    }

    private function system(int $runId, ?int $position, string $line): void
    {
        $this->logs->append($runId, $position, LogStream::System, $line, $this->clock->now());
    }
}
