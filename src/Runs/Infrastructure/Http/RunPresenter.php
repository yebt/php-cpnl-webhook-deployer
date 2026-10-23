<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure\Http;

use Deployer\Projects\Domain\Project;
use Deployer\Runs\Domain\Duration;
use Deployer\Runs\Domain\LogLine;
use Deployer\Runs\Domain\Run;
use Deployer\Runs\Domain\RunStepRecord;

final class RunPresenter
{
    /** @return array<string,mixed> */
    public static function run(Run $run, ?Project $project): array
    {
        return [
            'id' => $run->id,
            'project' => $project === null
                ? ['id' => $run->projectId, 'slug' => null, 'name' => null]
                : ['id' => $project->id, 'slug' => $project->slug, 'name' => $project->name],
            'status' => $run->status->value,
            'trigger' => $run->trigger,
            'trigger_meta' => $run->triggerMeta,
            'retry_of' => $run->retryOf,
            'action' => $run->action,
            'action_name' => $run->action === null ? null : ($run->actionName ?? $run->action),
            'cancel_requested' => $run->cancelRequested,
            'error' => $run->error,
            'created_at' => $run->createdAt,
            'started_at' => $run->startedAt,
            'finished_at' => $run->finishedAt,
            'duration_ms' => Duration::millis($run->startedAt, $run->finishedAt),
        ];
    }

    /** @return array<string,mixed> */
    public static function step(RunStepRecord $step): array
    {
        return [
            'position' => $step->position,
            'name' => $step->name,
            'command' => $step->command,
            'status' => $step->status->value,
            'exit_code' => $step->exitCode,
            'timeout' => $step->timeout,
            'continue_on_error' => $step->continueOnError,
            'started_at' => $step->startedAt,
            'finished_at' => $step->finishedAt,
            'duration_ms' => Duration::millis($step->startedAt, $step->finishedAt),
        ];
    }

    /** @return array{id: int, step: ?int, stream: string, line: string, at: string} */
    public static function logLine(LogLine $line): array
    {
        return [
            'id' => $line->id,
            'step' => $line->stepPosition,
            'stream' => $line->stream->value,
            'line' => $line->line,
            'at' => $line->loggedAt,
        ];
    }
}
