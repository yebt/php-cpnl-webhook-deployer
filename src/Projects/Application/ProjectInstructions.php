<?php

declare(strict_types=1);

namespace Deployer\Projects\Application;

use Deployer\Pipeline\Application\PipelineParser;
use Deployer\Pipeline\Domain\BuiltinVars;
use Deployer\Pipeline\Domain\PipelineValidationError;
use Deployer\Projects\Domain\InstructionsStore;
use Deployer\Projects\Domain\Project;

/** Reads and writes a project's pipeline file, validating it with the pipeline parser. */
final class ProjectInstructions
{
    public function __construct(
        private readonly InstructionsStore $store,
        private readonly PipelineParser $parser,
    ) {
    }

    /** @return array{exists: bool, content: string} */
    public function read(Project $project): array
    {
        if (!$this->store->exists($project)) {
            return ['exists' => false, 'content' => ''];
        }

        return ['exists' => true, 'content' => $this->store->read($project)];
    }

    /** @return list<string> validation errors (empty when valid) */
    public function validate(Project $project, string $content): array
    {
        try {
            // RUN_ID is unknown before a run exists; 0 only affects interpolated values.
            $this->parser->parse($content, new BuiltinVars($project->slug, 0, $project->folder));

            return [];
        } catch (PipelineValidationError $e) {
            return $e->errors();
        }
    }

    /**
     * Saves the file. Invalid content is rejected unless $force is true.
     *
     * @return list<string> validation errors of the saved content
     * @throws InvalidInstructions when invalid and not forced (nothing is written)
     */
    public function save(Project $project, string $content, bool $force): array
    {
        $errors = $this->validate($project, $content);
        if ($errors !== [] && !$force) {
            throw new InvalidInstructions($errors);
        }
        $this->store->write($project, $content);

        return $errors;
    }
}
