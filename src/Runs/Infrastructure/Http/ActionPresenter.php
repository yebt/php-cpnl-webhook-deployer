<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure\Http;

use Deployer\Pipeline\Domain\Action;
use Deployer\Pipeline\Domain\Step;
use Deployer\Projects\Domain\Project;
use Deployer\Runs\Application\InstructionsUnavailable;
use Deployer\Runs\Application\ProjectActions;
use Deployer\Runs\Application\UnknownAction;
use Deployer\Runs\Domain\RunStatus;
use Deployer\Shared\Infrastructure\Http\ApiError;
use Deployer\Shared\Infrastructure\Http\Response;

/** HTTP shapes shared by the UI and webhook action endpoints. */
final class ActionPresenter
{
    /** @return array{id: string, name: string, description: ?string, steps: list<string>} */
    public static function action(Action $action): array
    {
        return [
            'id' => $action->id,
            'name' => $action->name,
            'description' => $action->description,
            // Step names only: commands interpolated before a run exists would be misleading.
            'steps' => array_map(static fn (Step $step): string => $step->name, $action->steps),
        ];
    }

    /**
     * Queues an action run and answers 202, or maps the rejection to 404/422.
     *
     * @param array<string,string>|null $triggerMeta
     */
    public static function queued(ProjectActions $actions, Project $project, string $actionId, string $trigger, ?array $triggerMeta = null): Response
    {
        try {
            $runId = $actions->enqueue($project, $actionId, $trigger, $triggerMeta);
        } catch (UnknownAction $e) {
            throw new ApiError(404, 'unknown_action', $e->getMessage());
        } catch (InstructionsUnavailable $e) {
            throw new ApiError(422, 'invalid_instructions', $e->getMessage());
        }

        return Response::json(['run_id' => $runId, 'status' => RunStatus::Queued->value, 'action' => $actionId], 202);
    }
}
