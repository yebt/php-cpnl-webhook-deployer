<?php

declare(strict_types=1);

namespace Deployer\Runs\Application;

use Deployer\Pipeline\Application\PipelineParser;
use Deployer\Pipeline\Domain\Action;
use Deployer\Pipeline\Domain\BuiltinVars;
use Deployer\Pipeline\Domain\PipelineValidationError;
use Deployer\Projects\Domain\InstructionsStore;
use Deployer\Projects\Domain\Project;
use RuntimeException;

/**
 * Lists a project's actions and queues action runs. Both read the CURRENT
 * instructions file, so an unknown action or a broken file is rejected before
 * a run is created. The runner reads the file again when the run starts.
 */
final class ProjectActions
{
    public function __construct(
        private readonly InstructionsStore $instructions,
        private readonly PipelineParser $parser,
        private readonly EnqueueRun $enqueue,
    ) {
    }

    /**
     * @return array<string, Action> keyed by id, in declaration order
     * @throws InstructionsUnavailable when the file is missing, unreadable or invalid
     */
    public function list(Project $project): array
    {
        try {
            $yaml = $this->instructions->read($project);
            // RUN_ID is unknown before a run exists; 0 only affects interpolated values.
            $pipeline = $this->parser->parse($yaml, new BuiltinVars($project->slug, 0, $project->folder));
        } catch (PipelineValidationError $e) {
            throw new InstructionsUnavailable($e->getMessage(), previous: $e);
        } catch (RuntimeException $e) {
            throw new InstructionsUnavailable($e->getMessage(), previous: $e);
        }

        return $pipeline->actions;
    }

    /**
     * @param array<string,string>|null $triggerMeta
     * @return int the new run id
     * @throws InstructionsUnavailable
     * @throws UnknownAction
     */
    public function enqueue(Project $project, string $actionId, string $trigger, ?array $triggerMeta = null): int
    {
        $action = $this->list($project)[$actionId]
            ?? throw new UnknownAction(sprintf('Unknown action "%s" for project "%s".', $actionId, $project->slug));

        return ($this->enqueue)($project->id, $trigger, $triggerMeta, action: $action->id, actionName: $action->name);
    }
}
